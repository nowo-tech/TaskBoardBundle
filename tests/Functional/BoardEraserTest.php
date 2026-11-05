<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\EraseCursor;
use Nowo\TaskBoardBundle\Dto\EraseProgress;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Event\BoardErasedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function count;
use function ksort;
use function sort;

/**
 * Since 1.25: {@see TaskBoardEraser} empties or deletes a board in resumable slices, leaf-first, and never deletes
 * tracked time (owner rule): every manual entry is kept, detached, with its snapshots.
 */
final class BoardEraserTest extends FunctionalTestCase
{
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TaskBoardEvents::TASK_ERASED, TaskBoardEvents::BOARD_EMPTIED, TaskBoardEvents::BOARD_DELETED] as $name) {
            $dispatcher->addListener($name, function (object $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    public function testEmptyingInSlicesKeepsTheBoardTheColumnsAndEveryEntry(): void
    {
        $board  = $this->board();
        $parent = $this->task($board, 'Parent');
        $child  = $this->task($board, 'Child', 1, $parent);
        $leaf   = $this->task($board, 'Grandchild', 2, $child);
        $other  = $this->task($board, 'Other');
        $ids    = $this->logOn($parent, $child, $leaf, $other, $other);

        // One batch of 2 per call (the clock jumps past the budget after each batch).
        $eraser   = $this->eraser(new SteppingClock());
        $progress = $eraser->emptyBoard($board, $this->owner, 2);
        self::assertSame(2, $progress->deleted);
        self::assertSame(2, $progress->remaining);
        self::assertFalse($progress->done);
        self::assertInstanceOf(EraseCursor::class, $progress->cursor);
        self::assertSame(EraseCursor::PHASE_TASKS, $progress->cursor->phase);

        $calls = 1;
        while (!$progress->done) {
            // A worker passes the cursor through a message: it survives the array round trip.
            $progress = $eraser->emptyBoard($board, $this->owner, 2, EraseCursor::fromArray($progress->cursor?->toArray() ?? []));
            ++$calls;
        }
        // [Grandchild, Other], [Child], [Parent], then the empty check.
        self::assertSame(4, $calls);
        self::assertSame(0, $progress->remaining);
        self::assertNull($progress->cursor);

        // Leaf-first: a parent is announced only after its subtasks.
        $erased = $this->erasedTitles();
        self::assertSame(['Other', 'Grandchild', 'Child', 'Parent'], $this->leafOrder($erased));
        $byTitle = [];
        foreach ($this->eventsOf(TaskBoardEvents::TASK_ERASED) as $event) {
            self::assertInstanceOf(TaskErasedEvent::class, $event);
            self::assertSame($board->getId(), $event->boardId);
            self::assertSame([], $event->attachmentRefs);
            $byTitle[$event->title] = $event->manualTimeEntries;
        }
        ksort($byTitle);
        self::assertSame(['Child' => 1, 'Grandchild' => 1, 'Other' => 2, 'Parent' => 1], $byTitle);

        $done = $this->eventsOf(TaskBoardEvents::BOARD_EMPTIED);
        self::assertCount(1, $done);
        self::assertInstanceOf(BoardErasedEvent::class, $done[0]);
        self::assertSame(4, $done[0]->tasksDeleted);
        self::assertSame(0, $done[0]->columnsDeleted);
        self::assertFalse($done[0]->boardDeleted);
        self::assertSame((string) $this->owner->getId(), $done[0]->actorId);
        self::assertSame([], $this->eventsOf(TaskBoardEvents::BOARD_DELETED));

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));
        $kept = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $kept);
        self::assertCount(3, $kept->getColumns());
        $titles = array_map(fn (string $id): string => (string) $this->entry($id)->getTaskTitle(), $ids);
        sort($titles);
        self::assertSame(['Child', 'Grandchild', 'Other', 'Other', 'Parent'], $titles);
        foreach ($ids as $id) {
            self::assertTrue($this->entry($id)->isDetached());
            self::assertSame($board->getId(), $this->entry($id)->getBoardId());
            self::assertSame(10, $this->entry($id)->getMinutes());
        }

        // Idempotent: emptying an empty board is done at once.
        $this->events = [];
        $again        = $this->eraser()->emptyBoard($kept, $this->owner);
        self::assertTrue($again->done);
        self::assertSame(0, $again->deleted);
        self::assertCount(1, $this->eventsOf(TaskBoardEvents::BOARD_EMPTIED));
    }

    public function testDeletingRemovesTasksColumnsAndBoardButKeepsTheTime(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Spec');
        $sub   = $this->task($board, 'Spec part', 0, $task);
        $ids   = $this->logOn($task, $sub);

        $service = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $service);
        $progress = $service->deleteBoard($board, $this->owner, 500);
        self::assertEquals(new EraseProgress(2, 0, true, null), $progress);

        $deleted = $this->eventsOf(TaskBoardEvents::BOARD_DELETED);
        self::assertCount(1, $deleted);
        self::assertInstanceOf(BoardErasedEvent::class, $deleted[0]);
        self::assertSame(2, $deleted[0]->tasksDeleted);
        self::assertSame(3, $deleted[0]->columnsDeleted);
        self::assertTrue($deleted[0]->boardDeleted);

        $this->em->clear();
        self::assertNull($this->em->find(TaskBoard::class, $board->getId()));
        self::assertSame([], $this->em->getRepository(BoardColumn::class)->findAll());
        foreach ($ids as $id) {
            self::assertTrue($this->entry($id)->isDetached());
            self::assertSame($board->getId(), $this->entry($id)->getBoardIdSnapshot());
        }
    }

    public function testDeletingResumesThroughTheColumnAndBoardPhases(): void
    {
        $board = $this->board();
        $this->task($board, 'Only');

        $eraser = $this->eraser(new SteppingClock());
        $first  = $eraser->deleteBoard($board, $this->owner, 1);
        self::assertFalse($first->done);
        self::assertSame(1, $first->deleted);
        self::assertSame(EraseCursor::PHASE_TASKS, $first->cursor?->phase);

        // The next call finds no task left, removes the columns and the board.
        $last = $eraser->deleteBoard($board, $this->owner, 1, $first->cursor);
        self::assertTrue($last->done);
        self::assertSame(0, $last->deleted);
        $event = $this->eventsOf(TaskBoardEvents::BOARD_DELETED)[0] ?? null;
        self::assertInstanceOf(BoardErasedEvent::class, $event);
        self::assertSame(1, $event->tasksDeleted);

        // A cursor already past the tasks goes straight to the board.
        $board2 = $this->board('Second', []);
        $done   = $eraser->deleteBoard($board2, $this->owner, 10, new EraseCursor(EraseCursor::PHASE_BOARD, 3, 2));
        self::assertTrue($done->done);
        $event = $this->eventsOf(TaskBoardEvents::BOARD_DELETED)[1] ?? null;
        self::assertInstanceOf(BoardErasedEvent::class, $event);
        self::assertSame(3, $event->tasksDeleted);
        self::assertSame(2, $event->columnsDeleted);
        $this->em->clear();
        self::assertNull($this->em->find(TaskBoard::class, $board2->getId()));
    }

    public function testOnlyABoardManagerMayEraseIt(): void
    {
        $board    = $this->board();
        $stranger = $this->user('stranger@example.com');
        $this->task($board, 'Kept');

        try {
            $this->eraser()->emptyBoard($board, $stranger);
            self::fail('A stranger may not empty the board.');
        } catch (AccessDeniedException) {
        }
        $this->expectException(AccessDeniedException::class);
        try {
            $this->eraser()->deleteBoard($board, $stranger);
        } finally {
            self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));
            self::assertSame([], $this->events);
        }
    }

    public function testRemovingATaskFromTheBoardCollectionNoLongerDeletesIt(): void
    {
        // Audit FA-L1: orphan removal would delete the task without its PreRemove time detach.
        self::assertFalse($this->em->getClassMetadata(TaskBoard::class)->getAssociationMapping('tasks')->orphanRemoval);
        self::assertTrue($this->em->getClassMetadata(TaskBoard::class)->getAssociationMapping('tasks')->isCascadeRemove());

        $board = $this->board();
        $task  = $this->task($board, 'Stays');
        [$id]  = $this->logOn($task);
        $board->getTasks()->removeElement($task);
        $this->em->flush();
        $this->em->clear();
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $task->getId()));
        self::assertFalse($this->entry($id)->isDetached());
    }

    public function testDetachedSnapshotsCanBePurgedWithoutLosingTime(): void
    {
        $roadmap   = $this->board('Roadmap');
        $other     = $this->board('Other');
        $old       = $this->task($roadmap, 'Old secret');
        $new       = $this->task($roadmap, 'New secret');
        $live      = $this->task($roadmap, 'Live task');
        $foreign   = $this->task($other, 'Other board');
        $logger    = $this->timeLogger();
        $oldId     = $logger->log($old, $this->owner, 10, new DateTimeImmutable('2026-01-01'))->getId();
        $newId     = $logger->log($new, $this->owner, 20, new DateTimeImmutable('2026-10-01'))->getId();
        $liveId    = $logger->log($live, $this->owner, 30, new DateTimeImmutable('2026-01-01'))->getId();
        $foreignId = $logger->log($foreign, $this->owner, 40, new DateTimeImmutable('2026-01-01'))->getId();
        foreach ([$old, $new, $foreign] as $task) {
            $this->em->remove($task);
        }
        $this->em->flush();

        $purger = self::getContainer()->get('app.snapshot_purger');
        self::assertInstanceOf(TaskTimeEntrySnapshotPurger::class, $purger);
        self::assertSame(3, $purger->countWithSnapshots());
        self::assertSame(2, $purger->countWithSnapshots($roadmap->getId()));
        self::assertSame(1, $purger->countWithSnapshots($roadmap->getId(), new DateTimeImmutable('2026-06-01')));

        self::assertSame(1, $purger->purge($roadmap->getId(), new DateTimeImmutable('2026-06-01')));
        $this->em->clear();
        self::assertNull($this->entry($oldId)->getTaskTitle());
        self::assertNull($this->entry($oldId)->getTaskExternalId());
        self::assertSame($roadmap->getId(), $this->entry($oldId)->getBoardId());
        self::assertSame(10, $this->entry($oldId)->getMinutes());
        self::assertSame('New secret', $this->entry($newId)->getTaskTitle());

        self::assertSame(2, $purger->purge());
        self::assertSame(0, $purger->countWithSnapshots());
        $this->em->clear();
        self::assertNull($this->entry($foreignId)->getTaskTitleSnapshot());
        self::assertSame(40, $this->entry($foreignId)->getMinutes());
        // An entry of a live task is never touched.
        self::assertSame('Live task', $this->entry($liveId)->getTaskTitleSnapshot());
    }

    public function testTheCursorArrayIsValidated(): void
    {
        self::assertEquals(new EraseCursor(), EraseCursor::fromArray(['phase' => 'nope', 'tasksDeleted' => 'x', 'attachmentRefs' => 'x']));
        $cursor = EraseCursor::fromArray(['phase' => EraseCursor::PHASE_COLUMNS, 'tasksDeleted' => 4, 'columnsDeleted' => 1, 'attachmentRefs' => ['t1' => ['a', 3, 'b'], 5 => ['c'], 't2' => 'd']]);
        self::assertEquals(new EraseCursor(EraseCursor::PHASE_COLUMNS, 4, 1, ['t1' => ['a', 'b']]), $cursor);
        self::assertSame(['phase' => 'columns', 'tasksDeleted' => 4, 'columnsDeleted' => 1, 'attachmentRefs' => ['t1' => ['a', 'b']]], $cursor->toArray());
    }

    private function eraser(?ClockInterface $clock = null): TaskBoardEraser
    {
        $checker    = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $clock instanceof ClockInterface
            ? new TaskBoardEraser($this->em, $checker, $dispatcher, null, $clock, timeBudgetSeconds: 1)
            : new TaskBoardEraser($this->em, $checker, $dispatcher);
    }

    /**
     * @return list<object>
     */
    private function eventsOf(string $name): array
    {
        $found = [];
        foreach ($this->events as [$event, $payload]) {
            if ($event === $name) {
                $found[] = $payload;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function erasedTitles(): array
    {
        return array_map(static fn (object $event): string => $event instanceof TaskErasedEvent ? $event->title : '', $this->eventsOf(TaskBoardEvents::TASK_ERASED));
    }

    /**
     * The erase order with tasks of the same batch sorted (a batch is ordered by id, which is random).
     *
     * @param list<string> $titles
     *
     * @return list<string>
     */
    private function leafOrder(array $titles): array
    {
        self::assertSame(4, count($titles));
        $first = [$titles[0], $titles[1]];
        sort($first);
        self::assertSame(['Grandchild', 'Other'], $first);

        return ['Other', 'Grandchild', $titles[2], $titles[3]];
    }

    private function timeLogger(): TaskTimeLogger
    {
        $logger = self::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);

        return $logger;
    }

    /**
     * @return list<string>
     */
    private function logOn(Task ...$tasks): array
    {
        return array_map(fn (Task $task): string => $this->timeLogger()->log($task, $this->owner, 10, new DateTimeImmutable('2026-11-04'))->getId(), $tasks);
    }

    private function entry(string $id): TaskTimeEntry
    {
        $entry = $this->em->find(TaskTimeEntry::class, $id);
        self::assertInstanceOf(TaskTimeEntry::class, $entry, $id);

        return $entry;
    }
}

/** A clock that moves 10 minutes forward on every read (each batch overruns the budget). */
final class SteppingClock implements ClockInterface
{
    private int $now = 1_790_000_000;

    public function now(): DateTimeImmutable
    {
        $this->now += 600;

        return new DateTimeImmutable('@' . $this->now);
    }
}
