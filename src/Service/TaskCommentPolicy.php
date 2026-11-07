<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;

/**
 * Since 2.2.0 (spec 213): who may write, edit and delete a task's comments, for every write path. Readers comment unless
 * `comments.readers_can_comment` is false; only the author edits; the author or an editor of the task deletes.
 */
final readonly class TaskCommentPolicy
{
    public function __construct(
        private TaskBoardResourceAccessChecker $accessChecker,
        private bool $readersCanComment,
    ) {
    }

    public function mayComment(object $user, Task $task): bool
    {
        return $this->readersCanComment || $this->accessChecker->canEditTask($user, $task);
    }

    public function mayEdit(object $user, Task $task, TaskComment $comment): bool
    {
        return TaskCommentManager::isAuthor($comment, $user) && $this->mayComment($user, $task);
    }

    public function mayDelete(object $user, Task $task, TaskComment $comment): bool
    {
        return TaskCommentManager::isAuthor($comment, $user) || $this->accessChecker->canEditTask($user, $task);
    }
}
