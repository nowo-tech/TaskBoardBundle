<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use InvalidArgumentException;
use JsonException;
use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;

use function array_key_last;
use function array_pop;
use function count;
use function intdiv;
use function json_decode;
use function strcspn;
use function strlen;
use function strpos;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.22.1 (audits EB-L3, EB-L5, EB-I7, EB-L6): bounds a JSON import before anything is built. {@see self::decode()} first
 * weighs the raw text (the peak memory json_decode() would reach, in units of about 16 bytes) against
 * `import.max_json_nodes`,
 * then decodes with a depth limit; {@see self::assertRows()} refuses more items than `import.max_rows` before they are
 * mapped to tasks.
 */
final readonly class JsonImportGuard
{
    public const DEFAULT_MAX_NODES = 4_000_000;

    /*
     * The weight approximates the peak memory of json_decode(), in units of 16 bytes (audits EB-L5, EB-I7, EB-L6):
     * - a list starts at 12 (a PHP array with its first 8 slots); a slot is 1 (16 bytes, the scalar inside included);
     * - an object starts at 24 (a hash with its first 8 buckets); a bucket is 3 (32 bytes plus its hash slots);
     * - a list or an object grows by doubling: each time its element count passes the table size, the new table is
     *   added in full while the old one stays counted (PHP holds both while it copies), so a table of n elements weighs
     *   about twice the next power of two of n;
     * - a string (a key or a value) is 2 plus one per 16 bytes, quotes included.
     */

    /** What a new list (`[`) weighs: the array and its first table. */
    private const LIST_WEIGHT = 12;

    /** What a new object (`{`) weighs: the hash and its first table. */
    private const OBJECT_WEIGHT = 24;

    /** The first table size of a PHP array. */
    private const FIRST_TABLE = 8;

    /** What one slot of a list table weighs. */
    private const LIST_SLOT_WEIGHT = 1;

    /** What one bucket of an object table weighs. */
    private const OBJECT_SLOT_WEIGHT = 3;

    /** What one string weighs before its bytes. */
    private const STRING_WEIGHT = 2;

    /** Bytes of a string per weight unit (quotes included, rounded up). */
    private const STRING_BYTES_PER_UNIT = 16;

    public function __construct(
        private int $maxNodes = self::DEFAULT_MAX_NODES,
        private int $maxRows = 5000,
    ) {
    }

    /**
     * The decoded document (any JSON value), or InvalidArgumentException("$invalidPrefix" + the JSON error).
     *
     * @param int<1, max> $depth
     *
     * @throws ImportLimitExceededException when the document is too large to decode
     * @throws InvalidArgumentException when it is not valid JSON
     */
    public function decode(string $content, string $invalidPrefix, int $depth = 64): mixed
    {
        $this->weigh($content);
        try {
            return json_decode($content, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException($invalidPrefix . $exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param array<mixed> $items the items about to be mapped to tasks
     *
     * @throws ImportLimitExceededException
     */
    public function assertRows(array $items): void
    {
        if (count($items) > $this->maxRows) {
            throw ImportLimitExceededException::rows($this->maxRows, count($items));
        }
    }

    /**
     * Audit EB-I7: rows found while walking nested items (the own format's `subtasks`), checked one by one.
     *
     * @throws ImportLimitExceededException
     */
    public function assertRowCount(int $rows): void
    {
        if ($rows > $this->maxRows) {
            throw ImportLimitExceededException::rows($this->maxRows);
        }
    }

    /**
     * One pass over the text (see the weights above): strings by their length, lists and objects by their tables,
     * which grow as their commas are counted.
     */
    private function weigh(string $json): void
    {
        $length = strlen($json);
        $weight = 0;
        /** @var list<array{0: int, 1: int, 2: int}> $open the open containers: [slot weight, elements, table size] */
        $open = [];
        for ($i = 0; $i < $length; ++$i) {
            $i += strcspn($json, '"[{,]}', $i);
            if ($i >= $length) {
                return;
            }
            $char = $json[$i];
            if ($char === '"') {
                $end = $this->stringEnd($json, $i);
                $weight += self::STRING_WEIGHT + intdiv($end - $i + self::STRING_BYTES_PER_UNIT - 1, self::STRING_BYTES_PER_UNIT);
                $i = $end - 1;
            } elseif ($char === '[' || $char === '{') {
                $weight += $char === '[' ? self::LIST_WEIGHT : self::OBJECT_WEIGHT;
                $open[] = [$char === '[' ? self::LIST_SLOT_WEIGHT : self::OBJECT_SLOT_WEIGHT, 1, self::FIRST_TABLE];
            } elseif ($char === ',') {
                $top = array_key_last($open);
                if ($top !== null && ++$open[$top][1] > $open[$top][2]) {
                    // The table doubles; the old one is still counted (both are held while PHP copies).
                    $open[$top][2] *= 2;
                    $weight += $open[$top][2] * $open[$top][0];
                }
            } else {
                array_pop($open);
            }
            if ($weight > $this->maxNodes) {
                throw ImportLimitExceededException::jsonNodes($this->maxNodes);
            }
        }
    }

    /**
     * The offset after the string that opens at `$open` (an escaped quote does not close it); the length when it is
     * never closed (json_decode() then refuses the document).
     */
    private function stringEnd(string $json, int $open): int
    {
        for ($from = $open + 1;; $from = $close + 1) {
            $close = strpos($json, '"', $from);
            if ($close === false) {
                return strlen($json);
            }
            $backslashes = 0;
            for ($k = $close - 1; $json[$k] === '\\'; --$k) {
                ++$backslashes;
            }
            if ($backslashes % 2 === 0) {
                return $close + 1;
            }
        }
    }
}
