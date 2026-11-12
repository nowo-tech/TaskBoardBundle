<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;

/**
 * Since 2.12.0 (Beacon D-20261111-12): whether the period of a manual time entry is locked (approved) or waiting for
 * approval, so its `billable` flag can no longer change. `$user` is the entry's user (the host's user object), `$loggedAt`
 * the entry's day.
 *
 * No framework type (REQ-ARCH-001 rule 3). The default ({@see NoTaskTimePeriodLock}) locks nothing; with TimeTrackBundle
 * (>= 1.13) installed the container aliases this port to a bridge over TimeTrack's period lock (timesheets), so manual
 * entries follow the same locks as TimeTrack's own entries. Alias it to your own service for another lock source.
 */
interface TaskTimePeriodLockInterface
{
    public function isLocked(object $user, string $taskId, ?string $boardId, DateTimeImmutable $loggedAt): bool;
}
