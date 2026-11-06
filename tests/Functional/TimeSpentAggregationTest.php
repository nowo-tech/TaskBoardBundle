<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function class_exists;

/**
 * Since 1.26.1 (TimeTrack contract §9.3): the kernel's dispatcher routes timer stops and manual / calendar entries to
 * the task total, each entry once; entries on tasks that are not TaskBoard tasks are ignored without an error.
 */
final class TimeSpentAggregationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TimeEntryCreatedEvent::class)) {
            self::markTestSkipped('nowo-tech/time-track-bundle >= 1.14 is not installed.');
        }
        parent::setUp();
    }

    public function testTimerManualAndCalendarEntriesEachCountOnce(): void
    {
        $task = $this->task($this->board(), 'Write the spec');
        $this->em->flush();

        $dispatcher = $this->dispatcher();
        $dispatcher->dispatch(new TimerStopEvent($this->owner, $this->entry($task->getId(), 600, TimeEntrySource::Web)), TimeTrackEvents::TIMER_STOP);
        $dispatcher->dispatch(new TimeEntryCreatedEvent($this->entry($task->getId(), 1200, TimeEntrySource::Manual), 'manual'), TimeTrackEvents::TIME_ENTRY_CREATED);
        $dispatcher->dispatch(new TimeEntryCreatedEvent($this->entry($task->getId(), 300, TimeEntrySource::Calendar), 'calendar'), TimeTrackEvents::TIME_ENTRY_CREATED);

        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $reloaded);
        self::assertSame(2100, $reloaded->getTotalTimeSeconds());
    }

    public function testEntriesOnForeignTasksAreIgnored(): void
    {
        $task = $this->task($this->board(), 'Untouched');
        $this->em->flush();

        foreach (['PROJ-42', '', 'clickup:86c0abcd', $task->getId() . '-other'] as $foreignId) {
            $this->dispatcher()->dispatch(new TimeEntryCreatedEvent($this->entry($foreignId, 900, TimeEntrySource::Calendar), 'calendar'), TimeTrackEvents::TIME_ENTRY_CREATED);
        }

        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $reloaded);
        self::assertSame(0, $reloaded->getTotalTimeSeconds());
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function entry(string $taskId, int $seconds, TimeEntrySource $source): TimeEntry
    {
        return new TimeEntry($this->owner, $taskId, 'Task', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), $seconds, $source);
    }
}
