<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\EventListener;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\EventListener\TimeSpentAggregatorListener;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
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
}
