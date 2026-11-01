<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Since 1.21 (REQ-PERF-001): how many tasks a board, list or Gantt page shows out of the board's total
 * (`board.max_tasks`). The pages print "Showing N of M tasks" when {@see self::isCapped()}.
 */
final readonly class TaskWindow
{
    public function __construct(
        public int $shown,
        public int $total,
        public int $limit,
    ) {
    }

    /**
     * True when the board has more tasks than the page loaded.
     */
    public function isCapped(): bool
    {
        return $this->total > $this->shown;
    }
}
