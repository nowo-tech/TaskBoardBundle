<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

/**
 * Since 2.18.0 (FR-045): what {@see TaskBoardRestructureInterface::moveTasks()} did. Every id is kept: a moved task has
 * the same id, number (with `task_numbers.scope: tenant`), comments, time entries, tags and history as before, plus a
 * "moved to another board" history row.
 */
final readonly class TaskBoardMoveResult
{
    /**
     * @param list<string> $movedTaskIds every task moved, subtasks included, in the order they moved
     * @param list<string> $unchangedTaskIds tasks asked for that were already top-level tasks of the target
     * @param array<string, string> $columns moved task id => the target column id it landed in ('' for none)
     * @param list<string> $removedDependencyIds dependencies the target cannot keep (another board outside its folder)
     * @param list<string> $removedMemberIds members not assignable on the target
     * @param array<string, string|null> $formerParents moved task id => its parent's id before the move, null when top-level (`parent:<id>` in the history)
     */
    public function __construct(
        public string $targetBoardId,
        public array $movedTaskIds,
        public array $unchangedTaskIds,
        public array $columns,
        public array $removedDependencyIds,
        public array $removedMemberIds,
        public array $formerParents = [],
    ) {
    }
}
