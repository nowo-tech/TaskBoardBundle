<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Closure;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\OrderedRowLockInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function in_array;

/**
 * Beacon spec 239, FR-013, through a plain-PHP double of the lock (a DDD host's path): every row a write changes is locked
 * (columns, then tasks) inside the transaction before the first save, and the positions are the ones written without it.
 */
final class FixedLockOrderTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private TestUser $user;

    public function testAMoveLocksTheEnteredColumnThenEveryTaskOfItBeforeTheFirstSave(): void
    {
        foreach ([true, false] as $locked) {
            $this->log                       = [];
            [$board, $doing, $a, $b, $moved] = $this->scene();

            $this->taskManager([$a, $b, $moved], $locked)->move($moved, $doing->getId(), 1, $this->user);

            self::assertSame([0, 1, 2], [$a->getPosition(), $moved->getPosition(), $b->getPosition()]);
            self::assertSame($locked ? ['begin', 'columns ' . $doing->getId(), 'tasks ' . $moved->getId() . ',' . $a->getId() . ',' . $b->getId(), 'fresh ' . $doing->getId() . ' +' . $moved->getId(), 'save ' . $b->getTitle(), 'save ' . $moved->getTitle(), 'end'] : ['save ' . $b->getTitle(), 'save ' . $moved->getTitle()], $this->log);
        }
    }

    public function testAMoveWithinItsColumnLocksItsColumnToo(): void
    {
        [, $doing, $a, $b] = $this->scene();

        $this->taskManager([$a, $b], true)->move($b, $doing->getId(), 0, $this->user);

        self::assertSame(['begin', 'columns ' . $doing->getId(), 'tasks ' . $b->getId() . ',' . $a->getId(), 'fresh ' . $doing->getId() . ' +' . $b->getId(), 'save ' . $a->getTitle(), 'save ' . $b->getTitle(), 'end'], $this->log);
    }

    public function testAMoveThatWaitedRenumbersFromTheColumnAsCommittedNotFromItsEarlierRead(): void
    {
        [$board, $doing, $a, $b, $moved] = $this->scene();
        // Read before the lock: A (0), B (1). Committed meanwhile by a concurrent move: C entered at the top, A and B shifted.
        $c         = new Task($board, 'C', $this->user, column: $doing, position: 0);
        $committed = static function () use ($a, $b): void {
            $a->setPosition(1);
            $b->setPosition(2);
        };

        $this->taskManager([$a, $b, $moved], true, $committed, [$c])->move($moved, $doing->getId(), 1, $this->user);

        self::assertSame([0, 1, 2, 3], [$c->getPosition(), $moved->getPosition(), $a->getPosition(), $b->getPosition()]);
    }

    public function testAnUpdateThatChangesTheColumnLocksTheColumnThenTheTask(): void
    {
        [$board, $doing, , , $moved] = $this->scene();

        $this->taskManager([], true)->update($moved, new TaskFormData(title: 'Renamed', columnId: $doing->getId()), $this->user);

        self::assertSame(['begin', 'columns ' . $doing->getId(), 'tasks ' . $moved->getId(), 'save Renamed', 'end'], $this->log);
    }

    public function testAColumnReorderLocksEveryColumnBeforeItSavesThem(): void
    {
        [$board] = $this->scene();
        $ids     = array_map(static fn (BoardColumn $column): string => $column->getId(), $board->getColumns()->toArray());

        $this->columnManager(true)->reorder($board, [$ids[1], $ids[0]]);

        self::assertSame(['begin', 'columns ' . $ids[1] . ',' . $ids[0], 'save columns', 'end'], $this->log);
        self::assertSame([1, 0], array_map(static fn (BoardColumn $column): int => $column->getPosition(), $board->getColumns()->toArray()));
    }

    /**
     * @return array{0: TaskBoard, 1: BoardColumn, 2: Task, 3: Task, 4: Task}
     */
    private function scene(): array
    {
        $user       = new TestUser('1', 'dev@example.com');
        $this->user = $user;
        $board      = new TaskBoard('Demo', 'demo', $user);
        $todo       = new BoardColumn($board, 'Todo', 0);
        $doing      = new BoardColumn($board, 'Doing', 1);
        $board->addColumn($todo)->addColumn($doing);
        $a     = new Task($board, 'A', $user, column: $doing, position: 0);
        $b     = new Task($board, 'B', $user, column: $doing, position: 1);
        $moved = new Task($board, 'Moved', $user, column: $todo, position: 0);

        return [$board, $doing, $a, $b, $moved];
    }

    /**
     * @param list<Task> $tasks
     * @param (Closure(): void)|null $committed what a concurrent write committed before the lock was granted
     * @param list<Task> $entered tasks that write put in the column
     */
    private function taskManager(array $tasks, bool $locked, ?Closure $committed = null, array $entered = []): TaskManager
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturn($tasks);
        $repository->method('save')->willReturnCallback(function (Task $task): void {
            $this->log[] = 'save ' . $task->getTitle();
        });

        return new TaskManager($repository, new TaskChangeRecorder(), rowLock: $locked ? $this->lock(static function (?string $columnId, array $also) use ($tasks, $committed, $entered): array {
            if ($committed instanceof Closure) {
                $committed();
            }
            $fresh = [];
            foreach ([...$tasks, ...$entered] as $task) {
                if ($task->getColumn()?->getId() === $columnId || in_array($task->getId(), $also, true)) {
                    $fresh[] = $task;
                }
            }

            return $fresh;
        }) : null);
    }

    private function columnManager(bool $locked): BoardColumnManager
    {
        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->method('saveAll')->willReturnCallback(function (): void {
            $this->log[] = 'save columns';
        });

        return new BoardColumnManager($repository, rowLock: $locked ? $this->lock() : null);
    }

    /**
     * Records the calls as given (the Doctrine lock sorts the ids itself).
     */
    /**
     * @param (Closure(?string, list<string>): list<Task>)|null $fresh the column as committed
     */
    private function lock(?Closure $fresh = null): OrderedRowLockInterface
    {
        $record = function (string $entry): void {
            $this->log[] = $entry;
        };

        return new class($record, $fresh ?? static fn (): array => []) implements OrderedRowLockInterface {
            /**
             * @param Closure(string): void $record
             * @param Closure(?string, list<string>): list<Task> $fresh
             */
            public function __construct(private readonly Closure $record, private readonly Closure $fresh)
            {
            }

            public function lockedColumnTasks(string $boardId, ?string $columnId, array $alsoTaskIds = []): array
            {
                ($this->record)('fresh ' . $columnId . ' +' . implode(',', $alsoTaskIds));

                return ($this->fresh)($columnId, $alsoTaskIds);
            }

            public function transactional(Closure $work): mixed
            {
                ($this->record)('begin');
                $result = $work();
                ($this->record)('end');

                return $result;
            }

            public function lockColumns(array $columnIds): void
            {
                ($this->record)('columns ' . implode(',', $columnIds));
            }

            public function lockTasks(array $taskIds): void
            {
                ($this->record)('tasks ' . implode(',', $taskIds));
            }
        };
    }
}
