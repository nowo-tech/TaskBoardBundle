<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0: one page of the multi-board list. `total` counts every matching task; with a label filter it counts
 * the matches among the first `multi_board.scan_max_rows` tasks, and `partial` says the scan stopped there.
 */
final readonly class MultiBoardTaskPage
{
    /**
     * @param list<MultiBoardTaskRow> $rows
     * @param list<MultiBoardBoard> $boards the boards of the scope, after the bundle's read check
     * @param list<MultiBoardPerson> $assignees every assignee of a task of those boards (filter choices)
     * @param list<string> $columns the column names of those boards (filter choices)
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
        public array $boards,
        public array $assignees,
        public array $columns,
        public bool $partial = false,
    ) {
    }

    public function isEmptyScope(): bool
    {
        return $this->boards === [];
    }
}
