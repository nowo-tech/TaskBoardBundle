<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Support;

use InvalidArgumentException;
use JsonException;
use Nowo\TaskBoardBundle\Exception\ImportLimitExceededException;

use function count;
use function json_decode;
use function strcspn;
use function strlen;
use function strpos;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.22.1 (audit EB-L3): bounds a JSON import before anything is built. {@see self::decode()} first weighs the raw
 * text (every value 1, every object or array 10 — what json_decode() would allocate) against `import.max_json_nodes`,
 * then decodes with a depth limit; {@see self::assertRows()} refuses more items than `import.max_rows` before they are
 * mapped to tasks.
 */
final readonly class JsonImportGuard
{
    public const DEFAULT_MAX_NODES = 2_000_000;

    /** What one object or array weighs (a small PHP array costs about ten scalar values). */
    private const CONTAINER_WEIGHT = 10;

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
     * One pass over the text, strings skipped: commas count 1, `[` and `{` count {@see self::CONTAINER_WEIGHT}.
     */
    private function weigh(string $json): void
    {
        $length = strlen($json);
        $weight = 0;
        for ($i = 0; $i < $length;) {
            $i += strcspn($json, '"[{,', $i);
            if ($i >= $length) {
                return;
            }
            if ($json[$i] === '"') {
                $i = $this->stringEnd($json, $i);
                continue;
            }
            $weight += $json[$i] === ',' ? 1 : self::CONTAINER_WEIGHT;
            if ($weight > $this->maxNodes) {
                throw ImportLimitExceededException::jsonNodes($this->maxNodes);
            }
            ++$i;
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
