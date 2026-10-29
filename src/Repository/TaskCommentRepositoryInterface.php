<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;

/**
 * Since 1.16: persistence port for task comments.
 */
interface TaskCommentRepositoryInterface
{
    /** Persists and flushes. */
    public function save(TaskComment $comment): void;

    public function findById(string $id): ?TaskComment;

    /**
     * The task's comments, oldest first, deleted ones included, with their authors in the same query.
     *
     * @return list<TaskComment>
     */
    public function findByTask(Task $task): array;

    /**
     * Comments (not deleted) per task id, in one query; tasks without comments are absent.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, int>
     */
    public function countByTasks(array $taskIds): array;
}
