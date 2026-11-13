<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/** Since 1.23: {@see TaskBoardEvents::TASK_REMOTE_ARCHIVED}, after flush. */
final class TaskRemoteArchivedEvent extends Event
{
    public function __construct(
        /** @deprecated since 2.15.0, use getTaskId(), getBoardId() or getTaskSnapshot(); removed in 3.0 */
        public readonly Task $task,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $runId,
    ) {
    }

    /** Since 2.15.0 (REQ-ARCH-001 rule 4): the task's id. */
    public function getTaskId(): string
    {
        return $this->task->getId();
    }

    /** Since 2.15.0: the id of the task's board. */
    public function getBoardId(): string
    {
        return $this->task->getBoard()->getId();
    }

    /** Since 2.15.0: the task's tenant key (its board's; null without tenancy). */
    public function getTenantKey(): ?string
    {
        return $this->task->getBoard()->getTenantKey();
    }

    /** Since 2.15.0: the task as a framework-free snapshot, copied on each call. */
    public function getTaskSnapshot(): TaskSnapshot
    {
        return Snapshots::task($this->task);
    }
}
