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
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardErasedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskErasedEvent;
use Nowo\TaskBoardBundle\Exception\InvalidEraseCursorException;
use Nowo\TaskBoardBundle\Security\EraseCursorSigner;
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
use function str_repeat;

/**
 * Since 1.25: {@see TaskBoardEraser} empties or deletes a board in resumable slices, leaf-first, and never deletes
 * tracked time (owner rule): every manual entry is kept, detached, with its snapshots.
 */
final class BoardEraserTest extends FunctionalTestCase
{
    private const SECRET = 'eraser-test-secret';

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
        self::assertNotNull($progress->cursor->signature);

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

        // A (signed) cursor already past the tasks goes straight to the board.
        $board2 = $this->board('Second', []);
        $done   = $eraser->deleteBoard($board2, $this->owner, 10, $this->signed(new EraseCursor(EraseCursor::PHASE_BOARD, 3, 2), $board2, true));
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
        self::assertEquals(new EraseCursor(), EraseCursor::fromArray(['phase' => 'nope', 'tasksDeleted' => 'x', 'attachmentRefs' => 'x', 'signature' => '']));
        $cursor = EraseCursor::fromArray(['phase' => EraseCursor::PHASE_COLUMNS, 'tasksDeleted' => 4, 'columnsDeleted' => 1, 'attachmentRefs' => ['t1' => ['a', 3, 'b'], 5 => ['c'], 't2' => 'd'], 'signature' => 'abc']);
        self::assertEquals(new EraseCursor(EraseCursor::PHASE_COLUMNS, 4, 1, ['t1' => ['a', 'b']], 'abc'), $cursor);
        self::assertSame(['phase' => 'columns', 'tasksDeleted' => 4, 'columnsDeleted' => 1, 'attachmentRefs' => ['t1' => ['a', 'b']], 'signature' => 'abc'], $cursor->toArray());
        self::assertNull(EraseCursor::fromArray(['signature' => 5])->signature);
    }

    /**
     * Audit FP-L3: a cursor whose phase, counts or refs were changed (e.g. by a browser) is refused before any task goes.
     */
    public function testATamperedCursorIsRefused(): void
    {
        $board = $this->board();
        $first = $this->task($board, 'First');
        $this->task($board, 'Second');
        $this->task($board, 'Third');
        $eraser = $this->eraser(new SteppingClock());
        $cursor = $eraser->emptyBoard($board, $this->owner, 1)->cursor;
        self::assertInstanceOf(EraseCursor::class, $cursor);
        $data = $cursor->toArray();

        // A valid round trip through the array form is accepted (checked last, after the refusals).
        $tampered = [
            'phase' => ['phase' => EraseCursor::PHASE_BOARD] + $data,
            'tasks' => ['tasksDeleted' => 999] + $data,
            'cols'  => ['columnsDeleted' => 7] + $data,
            'refs'  => ['attachmentRefs' => [$first->getId() => ['storage/other-tenant/secret.pdf']]] + $data,
            'sig'   => ['signature' => str_repeat('0', 64)] + $data,
        ];
        $this->events = [];
        foreach ($tampered as $what => $array) {
            try {
                $eraser->emptyBoard($board, $this->owner, 10, EraseCursor::fromArray($array));
                self::fail('A tampered cursor (' . $what . ') must be refused.');
            } catch (InvalidEraseCursorException $exception) {
                self::assertStringContainsString('signature does not match', $exception->getMessage());
            }
        }
        self::assertSame([], $this->events);
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));

        $progress = $eraser->emptyBoard($board, $this->owner, 10, EraseCursor::fromArray($data));
        while (!$progress->done) {
            $progress = $eraser->emptyBoard($board, $this->owner, 10, EraseCursor::fromArray($progress->cursor?->toArray() ?? []));
        }
        $done = $this->eventsOf(TaskBoardEvents::BOARD_EMPTIED)[0] ?? null;
        self::assertInstanceOf(BoardErasedEvent::class, $done);
        self::assertSame(3, $done->tasksDeleted);
    }

    /**
     * Audit FP-L3: a cursor is bound to its board, its actor and its operation; an unsigned cursor is refused.
     */
    public function testACursorIsBoundToItsBoardActorAndOperation(): void
    {
        $board      = $this->board();
        $other      = $this->board('Other');
        $manager    = $this->user('manager@example.com');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($manager): void {
            if ($event->getSubject() === $manager) {
                $event->grant();
            }
        });
        foreach (['A', 'B'] as $title) {
            $this->task($board, $title);
            $this->task($other, $title);
        }
        $eraser = $this->eraser(new SteppingClock());
        $cursor = $eraser->emptyBoard($board, $this->owner, 1)->cursor;
        self::assertInstanceOf(EraseCursor::class, $cursor);
        $this->events = [];

        $attempts = [
            'another board'   => fn (): EraseProgress => $eraser->emptyBoard($other, $this->owner, 10, $cursor),
            'another actor'   => static fn (): EraseProgress => $eraser->emptyBoard($board, $manager, 10, $cursor),
            'other operation' => fn (): EraseProgress => $eraser->deleteBoard($board, $this->owner, 10, $cursor),
            'another secret'  => fn (): EraseProgress => $this->eraser(secret: 'another-secret')->emptyBoard($board, $this->owner, 10, $cursor),
        ];
        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                self::fail('The cursor must be refused for ' . $what . '.');
            } catch (InvalidEraseCursorException $exception) {
                self::assertStringContainsString('signature does not match', $exception->getMessage(), $what);
            }
        }

        try {
            $eraser->emptyBoard($board, $this->owner, 10, new EraseCursor(EraseCursor::PHASE_TASKS, 1));
            self::fail('An unsigned cursor must be refused.');
        } catch (InvalidEraseCursorException $exception) {
            self::assertStringContainsString('not signed', $exception->getMessage());
        }

        self::assertSame([], $this->events);
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]));
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $other->getId()]));
        // The manager may still start a fresh run on the board.
        self::assertTrue($this->eraser()->emptyBoard($board, $manager, 10)->done);
    }

    /**
     * Audit FP-L3: `emptyBoard()` has no column phase. A column cursor used to loop forever; it now finishes.
     */
    public function testEmptyingWithAColumnCursorFinishes(): void
    {
        $board    = $this->board();
        $progress = $this->eraser()->emptyBoard($board, $this->owner, 10, $this->signed(new EraseCursor(EraseCursor::PHASE_COLUMNS, 2), $board, false));
        self::assertTrue($progress->done);
        $done = $this->eventsOf(TaskBoardEvents::BOARD_EMPTIED)[0] ?? null;
        self::assertInstanceOf(BoardErasedEvent::class, $done);
        self::assertSame(2, $done->tasksDeleted);
        $this->em->clear();
        $kept = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $kept);
        self::assertCount(3, $kept->getColumns());
    }

    /**
     * Audit FP-L3: even a signed cursor may not jump past the tasks (skipping the unlink and TASK_ERASED) while the
     * board still has tasks or refs are pending.
     */
    public function testAPhaseJumpIsRefused(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Still here');

        foreach ([
            [new EraseCursor(EraseCursor::PHASE_BOARD), true, $board],
            [new EraseCursor(EraseCursor::PHASE_COLUMNS), false, $board],
            [new EraseCursor(EraseCursor::PHASE_COLUMNS, 0, 0, [$task->getId() => ['r']]), true, $this->board('Empty', [])],
        ] as [$cursor, $delete, $target]) {
            try {
                $delete
                    ? $this->eraser()->deleteBoard($target, $this->owner, 10, $this->signed($cursor, $target, true))
                    : $this->eraser()->emptyBoard($target, $this->owner, 10, $this->signed($cursor, $target, false));
                self::fail('A phase jump must be refused.');
            } catch (InvalidEraseCursorException $exception) {
                self::assertStringContainsString('is not allowed', $exception->getMessage());
            }
        }
        self::assertSame([], $this->events);
        $this->em->clear();
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $task->getId()));
        self::assertInstanceOf(TaskBoard::class, $this->em->find(TaskBoard::class, $board->getId()));
    }

    /**
     * Audit FP-L3: refs are handed to TASK_ERASED only for tasks that still belong to the board.
     */
    public function testRefsOfTasksNotOnTheBoardAreDropped(): void
    {
        $board   = $this->board();
        $other   = $this->board('Other');
        $mine    = $this->task($board, 'Mine');
        $foreign = $this->task($other, 'Foreign');
        $cursor  = $this->signed(new EraseCursor(EraseCursor::PHASE_TASKS, 0, 0, [
            $mine->getId()    => ['stored/mine.png'],
            $foreign->getId() => ['stored/foreign.png'],
            'gone-task-id'    => ['stored/gone.png'],
        ]), $board, false);

        self::assertTrue($this->eraser()->emptyBoard($board, $this->owner, 10, $cursor)->done);
        $erased = $this->eventsOf(TaskBoardEvents::TASK_ERASED);
        self::assertCount(1, $erased);
        self::assertInstanceOf(TaskErasedEvent::class, $erased[0]);
        self::assertSame($mine->getId(), $erased[0]->taskId);
        self::assertSame(['stored/mine.png'], $erased[0]->attachmentRefs);
        $this->em->clear();
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $foreign->getId()));
    }

    private function signed(EraseCursor $cursor, TaskBoard $board, bool $deleteBoard): EraseCursor
    {
        return (new EraseCursorSigner(self::SECRET))->sign($cursor, $board->getId(), (string) $this->owner->getId(), $deleteBoard);
    }

    private function eraser(?ClockInterface $clock = null, string $secret = self::SECRET): TaskBoardEraser
    {
        $checker    = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $clock instanceof ClockInterface
            ? new TaskBoardEraser($this->em, $checker, $dispatcher, null, $clock, timeBudgetSeconds: 1, cursorSecret: $secret)
            : new TaskBoardEraser($this->em, $checker, $dispatcher, cursorSecret: $secret);
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
