<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.10.0 (spec 216, DDD audit G3): {@see TaskBoardEvents::TASK_COMMENT_DELETED} — a comment was deleted (its row stays,
 * emptied), after it is saved. Ids only. TASK_COMMENT keeps its two actions (created, edited), so its listeners see no new one.
 */
final class TaskCommentDeletedEvent extends Event
{
    public function __construct(
        public readonly string $commentId,
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $actorId,
        public readonly ?string $cause = null,
    ) {
    }

    public static function of(TaskComment $comment, ?object $actor, ?string $cause): self
    {
        $task  = $comment->getTask();
        $board = $task->getBoard();

        return new self($comment->getId(), $task->getId(), $board->getId(), $board->getTenantKey(), ActorId::of($actor), $cause);
    }
}
