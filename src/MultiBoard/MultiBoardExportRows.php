<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use Closure;
use Generator;

/**
 * Since 2.13.0: the rows of an export, produced batch by batch while they are written (nothing is kept but the current
 * batch). `matching` counts every matching task (with a label filter: among the first `multi_board.scan_max_rows`), so
 * `truncated` says the export stops at `maxRows` before the end.
 */
final readonly class MultiBoardExportRows
{
    /**
     * @param Closure(): Generator<int, MultiBoardTaskRow> $rows
     */
    public function __construct(
        private Closure $rows,
        public int $matching,
        public int $maxRows,
    ) {
    }

    public function isTruncated(): bool
    {
        return $this->matching > $this->maxRows;
    }

    /**
     * @return Generator<int, MultiBoardTaskRow>
     */
    public function rows(): Generator
    {
        return ($this->rows)();
    }
}
