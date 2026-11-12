<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Service\TaskTimePeriodLockInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TimeTrackBundle\Repository\LockingGuardTimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.12.0 (TimeTrackBundle >= 1.13): a manual entry follows TimeTrack's period lock — locked when a timesheet of its
 * user whose period contains the entry's day is locked (approved) or submitted. A manual entry has a calendar day, not an
 * instant, so the day itself is compared with each timesheet's `periodStart` / `periodEnd`, whatever the timesheet's time
 * zone (audit MV-B-L1). The timesheets are read with a locking read when the store has one
 * ({@see LockingGuardTimesheetRepositoryInterface}, as for a move): call it inside the write's transaction (audit
 * MV-B-L2). The scope is the task's TimeTrack scope; without one every scope of the user counts (fail-safe, as TimeTrack
 * does for an unassigned entry). A user without an id is treated as locked. Timesheets off, or TimeTrackBundle installed
 * but not enabled: nothing is locked.
 */
final readonly class TimeTrackTaskTimePeriodLock implements TaskTimePeriodLockInterface
{
    public function __construct(
        private ?PeriodLockGuard $guard = null,
        private ?TimesheetScopeProviderInterface $scopes = null,
        private ?TimesheetRepositoryInterface $timesheets = null,
    ) {
    }

    public function isLocked(object $user, string $taskId, ?string $boardId, DateTimeImmutable $loggedAt): bool
    {
        if (!$this->guard instanceof PeriodLockGuard || !$this->guard->isEnabled() || !$this->timesheets instanceof TimesheetRepositoryInterface) {
            return false;
        }
        $userId = ActorId::of($user);
        if ($userId === null) {
            return true;
        }
        $scopeKey = $user instanceof UserInterface ? $this->scopes?->scopeKeyForTask($user, $taskId, $boardId) : null;
        $day      = $loggedAt->format('Y-m-d');
        $guarding = $this->timesheets instanceof LockingGuardTimesheetRepositoryInterface
            ? $this->timesheets->findGuardingForMove($userId, $scopeKey, $day, $day)
            : $this->timesheets->findGuarding($userId, $scopeKey, $day, $day);
        foreach ($guarding as $timesheet) {
            if ($timesheet->getPeriodStart() <= $day && $day <= $timesheet->getPeriodEnd()) {
                return true;
            }
        }

        return false;
    }
}
