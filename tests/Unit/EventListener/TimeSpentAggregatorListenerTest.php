<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\EventListener;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\EventListener\TimeSpentAggregatorListener;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use PHPUnit\Framework\TestCase;

final class TimeSpentAggregatorListenerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TimerStopEvent::class)) {
            self::markTestSkipped('nowo-tech/time-track-bundle is not installed.');
        }
    }

    public function testAddsDurationToTask(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $entry = new TimeEntry(
            $user,
            $task->getId(),
            'Work',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('findById')->with($task->getId())->willReturn($task);
        $repo->expects(self::once())->method('save')->with($task);

        (new TimeSpentAggregatorListener($repo))(new TimerStopEvent($user, $entry));

        self::assertSame(3600, $task->getTotalTimeSeconds());
    }

    public function testRefreshesManagedTaskBeforeAddingDuration(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);
        $task->addTimeSeconds(100);

        $entry = new TimeEntry(
            $user,
            $task->getId(),
            'Work',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->with($task)->willReturn(true);
        $em->expects(self::once())->method('refresh')->with($task)->willReturnCallback(static function (Task $managed): void {
            // Simulate another worker having already written a newer total.
            $managed->addTimeSeconds(50);
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Task::class)->willReturn($em);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn($task);
        $repo->expects(self::once())->method('save')->with($task);

        (new TimeSpentAggregatorListener($repo, $registry))(new TimerStopEvent($user, $entry));

        self::assertSame(3750, $task->getTotalTimeSeconds());
    }

    public function testNoOpWhenTaskMissing(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $entry = new TimeEntry(
            $user,
            'missing',
            'Work',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            60,
            TimeEntrySource::Web,
        );

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        (new TimeSpentAggregatorListener($repo))(new TimerStopEvent($user, $entry));
    }

    public function testZeroDurationEntryIsIgnoredWithoutAQuery(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $entry = new TimeEntry($user, 'any', 'Work', null, new DateTimeImmutable('-1 hour'), new DateTimeImmutable('-1 hour'), 0, TimeEntrySource::Web);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::never())->method('findById');
        $repo->expects(self::never())->method('save');

        (new TimeSpentAggregatorListener($repo))(new TimerStopEvent($user, $entry));
    }

    /** Since 1.26.1: manual and calendar entries (TimeTrack >= 1.14) add their duration like a timer stop. */
    public function testManualAndCalendarEntriesAddTheirDuration(): void
    {
        $this->requireEntryCreatedEvent();
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('findById')->with($task->getId())->willReturn($task);
        $repo->expects(self::exactly(2))->method('save')->with($task);

        $listener = new TimeSpentAggregatorListener($repo);
        $listener->onTimeEntryCreated(new TimeEntryCreatedEvent($this->entry($user, $task->getId(), 1800, TimeEntrySource::Manual), 'manual'));
        $listener->onTimeEntryCreated(new TimeEntryCreatedEvent($this->entry($user, $task->getId(), 900, TimeEntrySource::Calendar), 'calendar'));

        self::assertSame(2700, $task->getTotalTimeSeconds());
    }

    public function testCreatedEntryRefreshesTheManagedTaskFirst(): void
    {
        $this->requireEntryCreatedEvent();
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->with($task)->willReturn(true);
        $em->expects(self::once())->method('refresh')->with($task)->willReturnCallback(static function (Task $managed): void {
            $managed->addTimeSeconds(40);
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Task::class)->willReturn($em);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn($task);

        (new TimeSpentAggregatorListener($repo, $registry))->onTimeEntryCreated(new TimeEntryCreatedEvent($this->entry($user, $task->getId(), 60, TimeEntrySource::Manual), 'manual'));

        self::assertSame(100, $task->getTotalTimeSeconds());
    }

    /** An entry on another provider's task (not a TaskBoard task) is a silent no-op, never an exception. */
    public function testCreatedEntryOnAForeignTaskIsIgnored(): void
    {
        $this->requireEntryCreatedEvent();
        $user = new TestUser('1', 'dev@example.com');

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->expects(self::once())->method('findById')->with('PROJ-42')->willReturn(null);
        $repo->expects(self::never())->method('save');

        (new TimeSpentAggregatorListener($repo))->onTimeEntryCreated(new TimeEntryCreatedEvent($this->entry($user, 'PROJ-42', 600, TimeEntrySource::Calendar), 'calendar'));
    }

    private function requireEntryCreatedEvent(): void
    {
        if (!class_exists(TimeEntryCreatedEvent::class)) {
            self::markTestSkipped('nowo-tech/time-track-bundle >= 1.14 is not installed.');
        }
    }

    private function entry(TestUser $user, string $taskId, int $seconds, TimeEntrySource $source): TimeEntry
    {
        return new TimeEntry($user, $taskId, 'Work', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), $seconds, $source);
    }
}
