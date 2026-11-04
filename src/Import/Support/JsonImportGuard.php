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
 * Since 1.22.1 (audits EB-L3, EB-L5): bounds a JSON import before anything is built. {@see self::decode()} first weighs
 * the raw text (every value 1, every object or array 10, every string 2 plus one per 32 bytes, every object member 4
 * more — roughly what json_decode() would allocate) against `import.max_json_nodes`,
 * then decodes with a depth limit; {@see self::assertRows()} refuses more items than `import.max_rows` before they are
 * mapped to tasks.
 */
final readonly class JsonImportGuard
{
    public const DEFAULT_MAX_NODES = 2_000_000;

    /** What one object or array weighs (a small PHP array costs about ten scalar values). */
    private const CONTAINER_WEIGHT = 10;

    /** What one string weighs before its bytes (a PHP string has a header; audit EB-L5). */
    private const STRING_WEIGHT = 2;

    /** Bytes of a string per weight unit. */
    private const STRING_BYTES_PER_UNIT = 32;

    /** What one object member adds (its hash bucket; the key is weighed as a string). */
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
     * One pass over the text: commas count 1, `[` and `{` count {@see self::CONTAINER_WEIGHT}, each string (a key or
     * a value) {@see self::STRING_WEIGHT} plus its length / {@see self::STRING_BYTES_PER_UNIT}, each `:` (an object
     * member) {@see self::MEMBER_WEIGHT}.
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
                $weight += self::STRING_WEIGHT + intdiv($end - $i, self::STRING_BYTES_PER_UNIT);
                $i = $end;
            } else {
                $weight += match ($json[$i]) {
                    ','     => 1,
                    ':'     => self::MEMBER_WEIGHT,
                    default => self::CONTAINER_WEIGHT,
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
