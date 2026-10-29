<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskComment;

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

    public function getComment(): TaskComment
    {
        return $this->comment;
    }

    /** {@see self::CREATED} or {@see self::EDITED}. */
    public function getAction(): string
    {
        return $this->action;
    }
}
