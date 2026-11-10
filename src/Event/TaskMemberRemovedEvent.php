<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.11.0 (DDD audit G3): {@see TaskBoardEvents::TASK_MEMBER_REMOVED} — a user stopped being a member of a task
 * (assignee, reviewer, watcher…), after it is saved. Ids only: `userId` is the removed user's `getId()` as a string, `role`
 * the member role value, `actorId` who removed it. `cause` is {@see TaskChangeContext::current()} at the write.
 */
final class TaskMemberRemovedEvent extends Event
{
    public function __construct(
        public readonly string $memberId,
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $userId,
        public readonly string $role,
        public readonly ?string $actorId,
        public readonly ?string $cause = null,
    ) {
    }

    public static function of(Task $task, TaskMember $member, ?object $actor, ?string $cause): self
    {
        $board = $task->getBoard();

        return new self($member->getId(), $task->getId(), $board->getId(), $board->getTenantKey(), ActorId::of($member->getUser()), $member->getMemberRole()->value, ActorId::of($actor), $cause);
    }
}
