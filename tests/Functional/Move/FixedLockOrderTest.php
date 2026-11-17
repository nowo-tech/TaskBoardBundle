<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Doctrine\LockConflict;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmOrderedRowLock;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Throwable;

use function array_filter;
use function array_key_first;
use function array_map;
use function array_values;
use function fgets;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function sort;
use function str_starts_with;
use function stream_get_contents;
use function stripos;
use function usort;

use const PHP_BINARY;

/**
 * Beacon spec 239, block B, FR-013: the bundle's multi-row writes lock their rows in one fixed order (the columns, then the
 * tasks, each by ascending id) before the first write, so two of them never hold one row each while waiting for the
 * other's (MySQL 1213, PostgreSQL 40P01).
 *
 * Two kinds of proof:
 *  - the query log (every platform): the locking reads come first, by id, and every UPDATE after them;
 *  - a real second connection (MySQL and PostgreSQL only, skipped on SQLite, which serialises writers) plays a
 *    concurrent move of the column's lowest task L while the test moves its highest task H to the top. Before 2.21.0
 *    each move wrote in the order its unit of work issued the updates: moving H wrote L then H, and moving L wrote H
 *    then L. The two cross, and one of them is chosen as the deadlock victim. With the fixed order both lock L, then H:
 *    the test's move waits for the other one, and both finish.
 *
 * The other move is a raw two-row lock in the order the code it stands for takes (not a second kernel); the test's move
 * is the real `TaskManager::move()`.
 */
final class FixedLockOrderTest extends FunctionalTestCase
{
    /** How long the other connection waits between its two locks: the move under test is then waiting on its first lock. */
    private const OTHER_WAIT = 1_000_000;

    public function testAMoveLocksTheColumnAndThenItsTasksByIdBeforeItWrites(): void
    {
        [$board, $doing, $tasks] = $this->column();
        $moved                   = $this->task($board, 'Moved', 0);

        $queries = $this->logged(fn () => $this->service(TaskManager::class)->move($moved, $doing->getId(), 1, $this->owner));

        $locks = $this->lockReads($queries);
        self::assertCount(2, $locks, 'one lock of the entered column, one of the column\'s tasks');
        self::assertStringContainsString('task_board_columns', $locks[0]['sql']);
        self::assertSame([$doing->getId()], $locks[0]['ids']);
        $all = [$moved->getId(), ...array_map(static fn (Task $task): string => $task->getId(), $tasks)];
        sort($all);
        self::assertStringContainsString('task_board_tasks', $locks[1]['sql']);
        self::assertSame($all, $locks[1]['ids'], 'every task of the column, the moved one included, in ascending id order');
        self::assertGreaterThan($locks[1]['index'], $this->firstWrite($queries), 'nothing is written before the rows are locked');
        $this->assertPositions([$tasks[0], $moved, $tasks[1], $tasks[2]]);
    }

    public function testAColumnReorderLocksTheColumnsByIdBeforeItWrites(): void
    {
        $board   = $this->board('Reorder', ['A', 'B', 'C', 'D']);
        $columns = array_values($board->getColumns()->toArray());
        $ids     = array_map(static fn (BoardColumn $column): string => $column->getId(), $columns);

        $queries = $this->logged(fn () => $this->service(BoardColumnManager::class)->reorder($board, [$ids[3], $ids[1], $ids[0], $ids[2]], $this->owner));

        $locks = $this->lockReads($queries);
        self::assertCount(1, $locks);
        self::assertStringContainsString('task_board_columns', $locks[0]['sql']);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $locks[0]['ids']);
        self::assertGreaterThan($locks[0]['index'], $this->firstWrite($queries));
        $this->em->clear();
        foreach ([3 => 0, 1 => 1, 0 => 2, 2 => 3] as $index => $position) {
            self::assertSame($position, $this->em->find(BoardColumn::class, $ids[$index])?->getPosition());
        }
    }

    public function testAColumnDeletionLocksTheColumnsThenTheMovedTasksBeforeItWrites(): void
    {
        $board   = $this->board('Delete', ['A', 'B', 'C']);
        $columns = array_values($board->getColumns()->toArray());
        $first   = $this->task($board, 'First', 1, position: 0);
        $second  = $this->task($board, 'Second', 1, position: 1);

        // As a request finds them: the board's tasks loaded from the database.
        $this->em->clear();
        $deleted = $this->em->find(BoardColumn::class, $columns[1]->getId());
        $owner   = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertInstanceOf(BoardColumn::class, $deleted);
        self::assertNotNull($owner);

        $queries = $this->logged(fn () => self::assertTrue($this->service(BoardColumnManager::class)->delete($deleted, $owner)));

        $locks = $this->lockReads($queries);
        self::assertCount(2, $locks);
        $columnIds = array_map(static fn (BoardColumn $column): string => $column->getId(), $columns);
        sort($columnIds);
        self::assertStringContainsString('task_board_columns', $locks[0]['sql']);
        self::assertSame($columnIds, $locks[0]['ids'], 'the remaining columns and the deleted one');
        $taskIds = [$first->getId(), $second->getId()];
        sort($taskIds);
        self::assertSame($taskIds, $locks[1]['ids']);
        self::assertGreaterThan($locks[1]['index'], $this->firstWrite($queries));
        $this->em->clear();
        self::assertSame([$columns[0]->getId(), 0], [$this->em->find(Task::class, $first->getId())?->getColumn()?->getId(), $this->em->find(Task::class, $first->getId())?->getPosition()]);
        self::assertSame(1, $this->em->find(Task::class, $second->getId())?->getPosition());
    }

    public function testTheOldOrderCrossesAConcurrentMoveAndDeadlocks(): void
    {
        [, $doing, $tasks] = $this->column();
        $this->requireRowLocks();
        [$low, , $high] = $tasks;
        // As before 2.21.0: no lock service. The move of H saves L (the sibling it renumbers) and then H in one flush.
        $this->em->clear();
        $high = $this->em->find(Task::class, $high->getId());
        self::assertInstanceOf(Task::class, $high);
        $manager = new TaskManager(new DoctrineOrmTaskRepository($this->em), new TaskChangeRecorder());

        // The other move, of L, as before 2.21.0: H (a sibling it renumbers) first, then L.
        [$failure, $other] = $this->againstTheOtherMove($high->getId(), $low->getId(), static fn () => $manager->move($high, $doing->getId(), 0, $high->getCreator() ?? $high));

        $victim = ($failure instanceof Throwable && LockConflict::is($failure)) || stripos($other, 'deadlock') !== false;
        self::assertTrue($victim, 'one of the two crossing writes is chosen as the deadlock victim; the move said: ' . ($failure?->getMessage() ?? 'nothing') . '; the other said: ' . $other);
    }

    public function testTheFixedOrderWaitsForAConcurrentMoveAndBothFinish(): void
    {
        [, $doing, $tasks] = $this->column();
        $this->requireRowLocks();
        [$low, $middle, $high] = $tasks;
        $this->em->clear();
        $high = $this->em->find(Task::class, $high->getId());
        self::assertInstanceOf(Task::class, $high);
        $manager = new TaskManager(new DoctrineOrmTaskRepository($this->em), new TaskChangeRecorder(), rowLock: new DoctrineOrmOrderedRowLock($this->em));

        // The other move, of L, in the fixed order: L, then H.
        [$failure, $other] = $this->againstTheOtherMove($low->getId(), $high->getId(), static fn () => $manager->move($high, $doing->getId(), 0, $high->getCreator() ?? $high));

        self::assertNull($failure, 'the move waited for the other one: ' . $failure?->getMessage());
        self::assertSame('other: got the lock', $other);
        $this->assertPositions([$high, $low, $middle]);
    }

    /**
     * A board whose second column ("Doing") holds three tasks, by position in ascending id order.
     *
     * @return array{0: TaskBoard, 1: BoardColumn, 2: list<Task>}
     */
    private function column(): array
    {
        $board = $this->board();
        $doing = $board->getColumns()->get(1);
        self::assertInstanceOf(BoardColumn::class, $doing);
        $tasks = [$this->task($board, 'One', 1), $this->task($board, 'Two', 1), $this->task($board, 'Three', 1)];
        usort($tasks, static fn (Task $a, Task $b): int => $a->getId() <=> $b->getId());
        foreach ($tasks as $position => $task) {
            $task->setPosition($position);
        }
        $this->em->flush();

        return [$board, $doing, $tasks];
    }

    private function requireRowLocks(): void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers: there is no second connection to cross.');
        }
    }

    /**
     * Runs the other move on its own connection (it holds `$first`, then asks for `$second`) while `$move` runs here.
     *
     * @param callable(): mixed $move
     *
     * @return array{0: Throwable|null, 1: string} the move's failure, and what the other move printed
     */
    private function againstTheOtherMove(string $first, string $second, callable $move): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../Fixtures/Deadlock/lock_two_tasks.php', FunctionalKernel::databaseUrl(), $first, $second, (string) self::OTHER_WAIT], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        self::assertSame("locked\n", fgets($pipes[1]), 'the other move holds its first task');
        $failure = null;

        try {
            $move();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $other  = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        return [$failure, $other];
    }

    /**
     * @param callable(): mixed $write
     *
     * @return list<array{sql: string, params: array<mixed>}>
     */
    private function logged(callable $write): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $write();
        $queries = [];
        foreach ($holder->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $queries[] = ['sql' => (string) $query['sql'], 'params' => (array) ($query['params'] ?? [])];
            }
        }

        return $queries;
    }

    /**
     * The locking reads (`SELECT … WHERE id IN (…) ORDER BY id`, with `FOR UPDATE` where the platform has it), in order.
     *
     * @param list<array{sql: string, params: array<mixed>}> $queries
     *
     * @return list<array{index: int, sql: string, ids: list<string>}>
     */
    private function lockReads(array $queries): array
    {
        $locks = [];
        foreach ($queries as $index => $query) {
            if (preg_match('/^SELECT \w+\.id AS \w+ FROM (task_board_tasks|task_board_columns) \w+ WHERE \w+\.id IN \([?, ]+\) ORDER BY \w+\.id ASC/', $query['sql']) === 1) {
                if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                    self::assertStringContainsString('FOR UPDATE', $query['sql']);
                }
                $locks[] = ['index' => $index, 'sql' => $query['sql'], 'ids' => array_values(array_map('strval', $query['params']))];
            }
        }

        return $locks;
    }

    /**
     * @param list<array{sql: string, params: array<mixed>}> $queries
     */
    private function firstWrite(array $queries): int
    {
        $writes = array_filter($queries, static fn (array $query): bool => str_starts_with($query['sql'], 'UPDATE') || str_starts_with($query['sql'], 'DELETE') || str_starts_with($query['sql'], 'INSERT'));
        self::assertNotSame([], $writes);

        return (int) array_key_first($writes);
    }

    /**
     * @param list<Task> $tasks a column's tasks in the expected order
     */
    private function assertPositions(array $tasks): void
    {
        $connection = $this->em->getConnection();
        foreach ($tasks as $position => $task) {
            self::assertSame($position, (int) $connection->fetchOne('SELECT position FROM task_board_tasks WHERE id = ?', [$task->getId()]), $task->getTitle());
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
