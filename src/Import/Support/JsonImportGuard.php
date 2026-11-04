<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use InvalidArgumentException;
use JsonException;
use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;

use function count;
use function intdiv;
use function json_decode;
use function strcspn;
use function strlen;
use function strpos;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.22.1 (audits EB-L3, EB-L5, EB-I7): bounds a JSON import before anything is built. {@see self::decode()} first
 * weighs the raw text (about what json_decode() would allocate, in units of about 16 bytes) against
 * `import.max_json_nodes`,
 * then decodes with a depth limit; {@see self::assertRows()} refuses more items than `import.max_rows` before they are
 * mapped to tasks.
 */
final readonly class JsonImportGuard
{
    public const DEFAULT_MAX_NODES = 6_000_000;

    /*
     * The weights approximate what json_decode() allocates, in units of about 16 bytes (audits EB-L5, EB-I7): a value
     * slot of a list 1, an object member 4 more (its hash bucket and slot), a list 16 and an object 24 (a PHP array with
     * its minimum table), a string (key or value) 2 plus one per 16 bytes. The weight is then a memory budget: about
     * 16 bytes per unit for every shape measured (realistic exports about 11, the worst shapes about 13).
     */

    /** What one list (`[`) weighs. */
    private const LIST_WEIGHT = 16;

    /** What one object (`{`) weighs. */
    private const OBJECT_WEIGHT = 24;

    /** What one string weighs before its bytes. */
    private const STRING_WEIGHT = 2;

    /** Bytes of a string per weight unit (quotes included, rounded up). */
    private const STRING_BYTES_PER_UNIT = 16;

    /** What one object member adds (`:`; the key is weighed as a string). */
    private const MEMBER_WEIGHT = 4;

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
     * One pass over the text: commas count 1, `[` {@see self::LIST_WEIGHT}, `{` {@see self::OBJECT_WEIGHT}, each string
     * (a key or a value) {@see self::STRING_WEIGHT} plus one per {@see self::STRING_BYTES_PER_UNIT} bytes, each `:` (an
     * object member) {@see self::MEMBER_WEIGHT}.
     */
    private function weigh(string $json): void
    {
        $length = strlen($json);
        $weight = 0;
        for ($i = 0; $i < $length;) {
            $i += strcspn($json, '"[{,:', $i);
            if ($i >= $length) {
                return;
            }
            if ($json[$i] === '"') {
                $end = $this->stringEnd($json, $i);
                $weight += self::STRING_WEIGHT + intdiv($end - $i + self::STRING_BYTES_PER_UNIT - 1, self::STRING_BYTES_PER_UNIT);
                $i = $end;
            } else {
                $weight += match ($json[$i]) {
                    ','     => 1,
                    ':'     => self::MEMBER_WEIGHT,
                    '['     => self::LIST_WEIGHT,
                    default => self::OBJECT_WEIGHT,
                };
                ++$i;
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
