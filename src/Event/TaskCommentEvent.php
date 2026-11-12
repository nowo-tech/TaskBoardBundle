<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

/**
 * Since 1.16: a comment was created or edited — {@see TaskBoardEvents::TASK_COMMENT}, after flush.
 */
final class TaskCommentEvent extends AbstractTaskEvent
{
    public const CREATED = 'created';
    public const EDITED  = 'edited';

    public function __construct(
        object $actor,
        private readonly TaskComment $comment,
        private readonly string $action,
    ) {
        parent::__construct($comment->getTask(), $actor);
    }

    /**
     * @deprecated since 2.15.0, use {@see self::getCommentId()} or {@see self::getCommentSnapshot()}; removed in 3.0
     */
    public function getComment(): TaskComment
    {
        return $this->comment;
    }

    /** Since 2.10.0 (DDD audit G2). */
    public function getCommentId(): string
    {
        return $this->comment->getId();
    }

    /** {@see self::CREATED} or {@see self::EDITED}. */
    public function getAction(): string
    {
        return $this->action;
    }

    /** Since 2.15.0: the comment as a framework-free snapshot, copied on each call. */
    public function getCommentSnapshot(): TaskCommentSnapshot
    {
        return Snapshots::comment($this->comment);
    }
}
