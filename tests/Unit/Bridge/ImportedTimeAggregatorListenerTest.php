<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Bridge;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\ImportedTimeAggregatorListener;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.25: the task total follows imported TimeTrack entries (created, updated, moved, removed).
 */
final class ImportedTimeAggregatorListenerTest extends TestCase
{
    private TestUser $user;

    /** @var array<string, Task> */
    private array $tasks = [];

    protected function setUp(): void
    {
        if (!class_exists(TimeEntryImportedEvent::class)) {
            self::markTestSkipped('nowo-tech/time-track-bundle >= 1.11 is not installed.');
        }
        $this->user = new TestUser('1', 'dev@example.com');
        $board      = new TaskBoard('Demo', 'demo', $this->user);
        foreach (['a', 'b'] as $key) {
            $this->tasks[$key] = new Task($board, 'Task ' . $key, $this->user);
        }
    }

    public function testCreatedAndUpdatedEntriesAddTheirDelta(): void
    {
        $listener = new ImportedTimeAggregatorListener($this->repository());
        $listener->onImported(new TimeEntryImportedEvent($this->entry('a', 600), ImportedTimeEntryOutcome::Created));
        self::assertSame(600, $this->tasks['a']->getTotalTimeSeconds());

        // Updated from 600 to 900: +300; then down to 100: −800.
        $listener->onImported(new TimeEntryImportedEvent($this->entry('a', 900), ImportedTimeEntryOutcome::Updated, 600));
        self::assertSame(900, $this->tasks['a']->getTotalTimeSeconds());
        $listener->onImported(new TimeEntryImportedEvent($this->entry('a', 100), ImportedTimeEntryOutcome::Updated, 900));
        self::assertSame(100, $this->tasks['a']->getTotalTimeSeconds());

        // Same duration: no write.
        $listener->onImported(new TimeEntryImportedEvent($this->entry('a', 100), ImportedTimeEntryOutcome::Updated, 100));
        self::assertSame(100, $this->tasks['a']->getTotalTimeSeconds());
    }

    public function testAnEntryMovedToAnotherTaskLeavesTheOldOne(): void
    {
        $this->tasks['a']->addTimeSeconds(500);
        $listener = new ImportedTimeAggregatorListener($this->repository());
        $listener->onImported(new TimeEntryImportedEvent($this->entry('b', 700), ImportedTimeEntryOutcome::Updated, 500, $this->tasks['a']->getId()));

        self::assertSame(0, $this->tasks['a']->getTotalTimeSeconds());
        self::assertSame(700, $this->tasks['b']->getTotalTimeSeconds());
    }

    public function testRemovedEntriesSubtractPerTaskClampedAtZero(): void
    {
        $this->tasks['a']->addTimeSeconds(1000);
        $this->tasks['b']->addTimeSeconds(100);
        $listener = new ImportedTimeAggregatorListener($this->repository());
        $listener->onRemoved(new TimeEntriesRemovedEvent('clickup:dir', 'tasks', new ImportedTimeEntryRemoval(3, [
            $this->tasks['a']->getId() => 400,
            $this->tasks['b']->getId() => 250,
            'gone'                     => 30,
        ], ['1'])));

        self::assertSame(600, $this->tasks['a']->getTotalTimeSeconds());
        self::assertSame(0, $this->tasks['b']->getTotalTimeSeconds());
    }

    public function testRefreshesAManagedTaskBeforeMutating(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);
        $em->expects(self::once())->method('refresh')->willReturnCallback(static function (Task $managed): void {
            $managed->addTimeSeconds(50);
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Task::class)->willReturn($em);

        (new ImportedTimeAggregatorListener($this->repository(), $registry))->onImported(new TimeEntryImportedEvent($this->entry('a', 60), ImportedTimeEntryOutcome::Created));

        self::assertSame(110, $this->tasks['a']->getTotalTimeSeconds());
    }

    public function testSubtractTimeSecondsIgnoresNonPositiveValues(): void
    {
        $this->tasks['a']->addTimeSeconds(10);
        $this->tasks['a']->subtractTimeSeconds(0)->subtractTimeSeconds(-5);
        self::assertSame(10, $this->tasks['a']->getTotalTimeSeconds());
    }

    private function repository(): TaskRepositoryInterface
    {
        $byId = [];
        foreach ($this->tasks as $task) {
            $byId[$task->getId()] = $task;
        }
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(static fn (string $id): ?Task => $byId[$id] ?? null);

        return $repo;
    }

    private function entry(string $task, int $seconds): TimeEntry
    {
        return new TimeEntry($this->user, $this->tasks[$task]->getId(), 'Task ' . $task, null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), $seconds, TimeEntrySource::Import);
    }
}
