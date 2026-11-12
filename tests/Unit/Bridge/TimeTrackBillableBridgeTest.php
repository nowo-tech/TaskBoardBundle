<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimePeriodLock;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Repository\LockingGuardTimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.12.0 (Beacon D-20261111-12): manual entries share TimeTrack's billable default and period lock.
 */
final class TimeTrackBillableBridgeTest extends TestCase
{
    public function testTheDefaultIsTimeTracksWithTheTasksScope(): void
    {
        $user     = new TestUser('7', 'ana@example.com');
        $defaults = $this->createMock(TimeEntryBillableDefaultProviderInterface::class);
        $defaults->expects(self::exactly(3))->method('defaultFor')->willReturnCallback(static fn (string $scope, ?string $task): ?bool => $scope === 'org-a' ? true : null);
        $scopes = $this->createMock(TimesheetScopeProviderInterface::class);
        $scopes->expects(self::once())->method('scopeKeyForTask')->with($user, 'task-1', 'board-1')->willReturn('org-a');

        self::assertTrue((new TimeTrackTaskTimeBillableDefaultProvider($defaults, $scopes))->defaultFor($user, 'task-1', 'board-1'));
        self::assertNull((new TimeTrackTaskTimeBillableDefaultProvider($defaults))->defaultFor($user, 'task-1', 'board-1'), "no scope provider: ''");
        self::assertNull((new TimeTrackTaskTimeBillableDefaultProvider($defaults, $scopes))->defaultFor(new stdClass(), 'task-1', null), 'not a security user');
        self::assertNull((new TimeTrackTaskTimeBillableDefaultProvider())->defaultFor($user, 'task-1', null), 'TimeTrack installed but not enabled');
    }

    public function testTheLockIsTimeTracksTimesheetLock(): void
    {
        $user   = new TestUser('7', 'ana@example.com');
        $repo   = $this->repository([$this->lockedWeek($user, 'UTC')]);
        $scopes = $this->createMock(TimesheetScopeProviderInterface::class);
        $scopes->method('scopeKeyForTask')->willReturn('org-a');
        $lock = new TimeTrackTaskTimePeriodLock(new PeriodLockGuard($repo), $scopes, $repo);

        self::assertTrue($lock->isLocked($user, 'task-1', 'board-1', new DateTimeImmutable('2026-11-10')), 'inside the locked week');
        self::assertFalse($lock->isLocked($user, 'task-1', 'board-1', new DateTimeImmutable('2026-11-17')), 'the next week');
        self::assertFalse($lock->isLocked(new TestUser('8', 'bob@example.com'), 'task-1', null, new DateTimeImmutable('2026-11-10')), 'another person');
        self::assertTrue($lock->isLocked(new stdClass(), 'task-1', null, new DateTimeImmutable('2026-11-10')), 'no id: fail closed');
        self::assertFalse((new TimeTrackTaskTimePeriodLock(new PeriodLockGuard($repo, false), null, $repo))->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-10')), 'timesheets off');
        self::assertFalse((new TimeTrackTaskTimePeriodLock())->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-10')), 'TimeTrack installed but not enabled');
    }

    /** Audit MV-B-L1: the entry's calendar day against the period, whatever the timesheet's zone (UTC+13 here). */
    public function testTheDayIsComparedInEveryTimeZone(): void
    {
        $user = new TestUser('7', 'ana@example.com');
        $repo = $this->repository([$this->lockedWeek($user, 'Pacific/Auckland')]);
        $lock = new TimeTrackTaskTimePeriodLock(new PeriodLockGuard($repo), null, $repo);

        self::assertTrue($lock->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-15 00:00', new DateTimeZone('UTC'))), 'the Sunday of the locked week');
        self::assertTrue($lock->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-09 00:00', new DateTimeZone('Pacific/Kiritimati'))), 'its Monday, logged in UTC+14');
        self::assertFalse($lock->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-16 00:00', new DateTimeZone('UTC'))), 'the next Monday');
        self::assertFalse($lock->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-08 00:00', new DateTimeZone('Pacific/Auckland'))), 'the Sunday before');
    }

    /** Audit MV-B-L2: with a store that has one, the timesheets are read with the locking read of a move. */
    public function testALockingStoreIsReadWithItsLockingRead(): void
    {
        $user = new TestUser('7', 'ana@example.com');
        $repo = $this->createMockForIntersectionOfInterfaces([TimesheetRepositoryInterface::class, LockingGuardTimesheetRepositoryInterface::class]);
        $repo->expects(self::never())->method('findGuarding');
        $repo->expects(self::once())->method('findGuardingForMove')->with('7', null, '2026-11-10', '2026-11-10')->willReturn([$this->lockedWeek($user, 'UTC')]);

        self::assertTrue((new TimeTrackTaskTimePeriodLock(new PeriodLockGuard($repo), null, $repo))->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-10')));
    }

    private function lockedWeek(TestUser $user, string $timezone): Timesheet
    {
        $timesheet = new Timesheet($user, '7', 'org-a', Period::fromKey(TimesheetPeriodKind::Week, '2026-W46'), $timezone, new DateTimeImmutable('2026-11-09'));
        $timesheet->applyLock(new DateTimeImmutable('2026-11-16'), TimesheetLockReason::Manual);

        return $timesheet;
    }

    /**
     * @param list<Timesheet> $timesheets
     */
    private function repository(array $timesheets): TimesheetRepositoryInterface
    {
        $repo = $this->createMock(TimesheetRepositoryInterface::class);
        $repo->method('findGuarding')->willReturnCallback(static fn (string $ownerId): array => $ownerId === '7' ? $timesheets : []);

        return $repo;
    }
}
