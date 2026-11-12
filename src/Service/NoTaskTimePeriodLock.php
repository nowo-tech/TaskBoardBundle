<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;

/**
 * Since 2.12.0: no period lock (without TimeTrackBundle manual entries have no timesheet).
 */
final class NoTaskTimePeriodLock implements TaskTimePeriodLockInterface
{
    public function isLocked(object $user, string $taskId, ?string $boardId, DateTimeImmutable $loggedAt): bool
    {
        return false;
    }
}
