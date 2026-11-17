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
            self::assertSame($locked ? ['begin', 'columns ' . $doing->getId(), 'tasks ' . $moved->getId() . ',' . $a->getId() . ',' . $b->getId(), 'save ' . $b->getTitle(), 'save ' . $moved->getTitle(), 'end'] : ['save ' . $b->getTitle(), 'save ' . $moved->getTitle()], $this->log);
        }
    }

    public function testAMoveWithinItsColumnLocksNoColumn(): void
    {
        [$board, $doing, $a, $b] = $this->scene();

        $this->taskManager([$a, $b], true)->move($b, $doing->getId(), 0, $this->user);

        self::assertSame(['begin', 'tasks ' . $b->getId() . ',' . $a->getId(), 'save ' . $a->getTitle(), 'save ' . $b->getTitle(), 'end'], $this->log);
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
     */
    private function taskManager(array $tasks, bool $locked): TaskManager
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $repository->method('findByBoard')->willReturn($tasks);
        $repository->method('save')->willReturnCallback(function (Task $task): void {
            $this->log[] = 'save ' . $task->getTitle();
        });

        return new TaskManager($repository, new TaskChangeRecorder(), rowLock: $locked ? $this->lock() : null);
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
    private function lock(): OrderedRowLockInterface
    {
        $record = function (string $entry): void {
            $this->log[] = $entry;
        };

        return new class($record) implements OrderedRowLockInterface {
            /**
             * @param Closure(string): void $record
             */
            public function __construct(private readonly Closure $record)
            {
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
