<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

use function count;

/**
 * Since 2.3.0 (spec 217, FR-001): what moving a task and its subtasks to another board will do, read before anything is
 * written. Counts and labels, never content. What is lost comes first: removed members, removed dependencies, a provider
 * link that stops, re-import keys that no longer match. `fingerprint` changes with any fact, so a confirmation of an older
 * plan is refused (FR-004).
 */
final readonly class TaskMovePlan
{
    /**
     * @param list<string> $taskIds the task, then its descendants
     * @param list<array{source: string, sourceId: string|null, target: string|null, targetId: string|null, tasks: int}> $columns one row per column used
     * @param array<string, string> $columnMap source column id => target column id, as applied
     * @param list<string> $membersRemoved labels of the members not assignable on the target
     * @param list<string> $memberIds ids of the TaskMember rows that are removed
     * @param list<string> $dependenciesRemoved titles of the tasks that stay, whose dependency with a moved task is removed
     * @param list<string> $dependencyIds ids of the dependencies that are removed
     */
    public function __construct(
        public Task $task,
        public TaskBoard $sourceBoard,
        public TaskBoard $targetBoard,
        public array $taskIds,
        public bool $becomesTopLevel,
        public array $columns,
        public array $columnMap,
        public array $membersRemoved,
        public array $memberIds,
        public array $dependenciesRemoved,
        public array $dependencyIds,
        public int $openTasks,
        public int $comments,
        public int $documents,
        public int $links,
        public int $timeEntries,
        public int $timePeople,
        public int $trackedSeconds,
        public int $syncDetached,
        public int $reimportKeys,
        public bool $numberChanges,
        public string $fingerprint,
        /** @var list<string> Since audit IC-I4: imported field names with values on the tree; they stay, read-only. */
        public array $importedFields = [],
        /** Since audit IC-L2: {@see TaskMovePlanner::snapshot()} when the plan was made. */
        public string $snapshot = '',
    ) {
    }

    public function subtasks(): int
    {
        return count($this->taskIds) - 1;
    }
}
