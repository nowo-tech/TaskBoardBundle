<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0: the work of one assignee over the boards of the scope (`person` null: the tasks nobody is assigned to).
 * A task with two assignees counts for both. Minutes: the estimate of the open tasks, and the time logged on all of them.
 */
final readonly class MultiBoardWorkloadRow
{
    public function __construct(
        public ?MultiBoardPerson $person,
        public int $open,
        public int $overdue,
        public int $completed,
        public int $estimatedMinutes,
        public int $loggedMinutes,
    ) {
    }
}
