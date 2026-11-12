<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Bridge;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimePeriodLock;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
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
        $user      = new TestUser('7', 'ana@example.com');
        $timesheet = new Timesheet($user, '7', 'org-a', Period::fromKey(TimesheetPeriodKind::Week, '2026-W46'), 'UTC', new DateTimeImmutable('2026-11-09'));
        $timesheet->applyLock(new DateTimeImmutable('2026-11-16'), TimesheetLockReason::Manual);
        $repo = $this->createMock(TimesheetRepositoryInterface::class);
        $repo->method('findGuarding')->willReturnCallback(static fn (string $ownerId): array => $ownerId === '7' ? [$timesheet] : []);
        $scopes = $this->createMock(TimesheetScopeProviderInterface::class);
        $scopes->method('scopeKeyForTask')->willReturn('org-a');
        $lock = new TimeTrackTaskTimePeriodLock(new PeriodLockGuard($repo), $scopes);

        self::assertTrue($lock->isLocked($user, 'task-1', 'board-1', new DateTimeImmutable('2026-11-10')), 'inside the locked week');
        self::assertFalse($lock->isLocked($user, 'task-1', 'board-1', new DateTimeImmutable('2026-11-17')), 'the next week');
        self::assertFalse($lock->isLocked(new TestUser('8', 'bob@example.com'), 'task-1', null, new DateTimeImmutable('2026-11-10')), 'another person');
        self::assertTrue($lock->isLocked(new stdClass(), 'task-1', null, new DateTimeImmutable('2026-11-10')), 'no id: fail closed');
        self::assertFalse((new TimeTrackTaskTimePeriodLock(new PeriodLockGuard($repo, false)))->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-10')), 'timesheets off');
        self::assertFalse((new TimeTrackTaskTimePeriodLock())->isLocked($user, 'task-1', null, new DateTimeImmutable('2026-11-10')), 'TimeTrack installed but not enabled');
    }
}
