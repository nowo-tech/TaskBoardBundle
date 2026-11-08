<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.3.0 (spec 217): a task and its subtasks moved to another board — {@see TaskBoardEvents::TASK_BOARD_CHANGED},
 * after the commit. The task is the one moved (its subtasks came with it); `taskIds` lists them all, so a host updates
 * what it keeps per task (time snapshots, file contexts) by id, in batches. No TASK_MOVED is dispatched for a board change.
 */
final class TaskBoardChangedEvent extends AbstractTaskEvent
{
    /**
     * @param list<string> $taskIds the moved task first, then its descendants
     */
    public function __construct(
        Task $task,
        object $actor,
        public readonly string $sourceBoardId,
        public readonly string $targetBoardId,
        public readonly array $taskIds,
    ) {
        parent::__construct($task, $actor);
    }
}
