<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.12.0 (Beacon D-20261111-12): {@see TaskBoardEvents::TASK_TIME_BILLABLE_CHANGED} — the `billable` flag of a manual
 * time entry changed, after the write is saved. Ids and scalars only: who (`actorId`), whose time (`userId`), the old and
 * the new value. The task's history keeps the same change (`time_billable`).
 */
final class TaskTimeBillableChangedEvent extends Event
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $taskId,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly ?string $userId,
        public readonly ?string $actorId,
        public readonly ?bool $oldBillable,
        public readonly ?bool $newBillable,
        public readonly ?string $cause = null,
    ) {
    }
}
