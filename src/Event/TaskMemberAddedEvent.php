<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Support\ActorId;

/**
 * A user became a member of a task (assignee, reviewer, watcher…) — {@see TaskBoardEvents::TASK_MEMBER_ADDED}.
 */
final class TaskMemberAddedEvent extends AbstractTaskEvent
{
    public function __construct(
        Task $task,
        object $actor,
        private readonly TaskMember $member,
    ) {
        parent::__construct($task, $actor);
    }

    public function getMember(): TaskMember
    {
        return $this->member;
    }

    /**
     * The user added to the task.
     */
    public function getUser(): object
    {
        return $this->member->getUser();
    }

    /** Since 2.10.0 (DDD audit G2). */
    public function getMemberId(): string
    {
        return $this->member->getId();
    }

    /** Since 2.10.0 (DDD audit G2): the added user's `getId()` as a string. */
    public function getUserId(): ?string
    {
        return ActorId::of($this->member->getUser());
    }

    /** Since 2.10.0 (DDD audit G2): `assignee`, `watcher`, `reviewer`… */
    public function getRole(): string
    {
        return $this->member->getMemberRole()->value;
    }
}
