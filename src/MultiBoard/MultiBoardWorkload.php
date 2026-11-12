<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0: the multi-board list grouped by person (most open tasks first, unassigned last).
 */
final readonly class MultiBoardWorkload
{
    /**
     * @param list<MultiBoardWorkloadRow> $rows
     * @param list<MultiBoardBoard> $boards
     * @param list<MultiBoardPerson> $assignees
     * @param list<string> $columns
     */
    public function __construct(
        public array $rows,
        public array $boards,
        public array $assignees,
        public array $columns,
        public bool $partial = false,
    ) {
    }
}
