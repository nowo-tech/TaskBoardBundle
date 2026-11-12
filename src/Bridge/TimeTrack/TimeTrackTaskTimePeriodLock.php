<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Service\TaskTimePeriodLockInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.12.0 (TimeTrackBundle >= 1.13): a manual entry follows TimeTrack's period lock — locked when a timesheet of its
 * user covering its day is locked (approved) or submitted. The scope is the task's TimeTrack scope; without one every
 * scope of the user counts (fail-safe, as TimeTrack does for an unassigned entry). A user without an id is treated as
 * locked. Timesheets off, or TimeTrackBundle installed but not enabled: nothing is locked.
 */
final readonly class TimeTrackTaskTimePeriodLock implements TaskTimePeriodLockInterface
{
    public function __construct(
        private ?PeriodLockGuard $guard = null,
        private ?TimesheetScopeProviderInterface $scopes = null,
    ) {
    }

    public function isLocked(object $user, string $taskId, ?string $boardId, DateTimeImmutable $loggedAt): bool
    {
        if (!$this->guard instanceof PeriodLockGuard || !$this->guard->isEnabled()) {
            return false;
        }
        $userId = ActorId::of($user);
        if ($userId === null) {
            return true;
        }
        $scopeKey = $user instanceof UserInterface ? $this->scopes?->scopeKeyForTask($user, $taskId, $boardId) : null;

        // The entry has a day, not a time: its middle, so every timesheet time zone sees the same local date.
        return $this->guard->blockingTimesheetOf($userId, $scopeKey, $loggedAt->setTime(12, 0)) instanceof Timesheet;
    }
}
