<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Entity\TaskComment;

/**
 * Since 1.16: a comment mentions people — {@see TaskBoardEvents::TASK_MENTION}, after flush. On an edit only the
 * people the edit adds; never the author. The users are the mention candidates' objects (people who can read the task
 * per the host's {@see TaskMentionCandidatesResolverInterface}).
 */
final class TaskMentionEvent extends AbstractTaskEvent
{
    /**
     * @param list<object> $users
     */
    public function __construct(
        object $actor,
        private readonly TaskComment $comment,
        private readonly array $users,
    ) {
        parent::__construct($comment->getTask(), $actor);
    }

    public function getComment(): TaskComment
    {
        return $this->comment;
    }

    /** @return list<object> */
    public function getMentionedUsers(): array
    {
        return $this->users;
    }
}
