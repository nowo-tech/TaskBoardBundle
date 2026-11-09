<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Support\ActorId;

use function array_filter;
use function array_map;
use function array_values;

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

    /** Since 2.10.0 (DDD audit G2). */
    public function getCommentId(): string
    {
        return $this->comment->getId();
    }

    /**
     * Since 2.10.0 (DDD audit G2): the mentioned users' `getId()` as strings (users without a scalar id are left out).
     *
     * @return list<string>
     */
    public function getMentionedUserIds(): array
    {
        return array_values(array_filter(array_map(ActorId::of(...), $this->users), static fn (?string $id): bool => $id !== null));
    }
}
