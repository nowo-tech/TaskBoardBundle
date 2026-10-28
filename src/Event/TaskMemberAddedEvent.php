<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;

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
}
