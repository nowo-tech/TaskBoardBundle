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
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
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
        self::assertCount(3, $locks, 'the entered column, then its tasks, then the moved task');
        self::assertSame(['task_board_columns', [$doing->getId()]], [$locks[0]['table'], $locks[0]['ids']]);
        self::assertSame(['task_board_tasks', [$doing->getId()]], [$locks[1]['table'], $locks[1]['ids']], 'the column\'s tasks, by id');
        self::assertSame(['task_board_tasks', [$moved->getId()]], [$locks[2]['table'], $locks[2]['ids']]);
        self::assertGreaterThan($locks[2]['index'], $this->firstWrite($queries), 'nothing is written before the rows are locked');
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
        self::assertSame('task_board_columns', $locks[0]['table']);
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
        self::assertCount(3, $locks);
        $columnIds = array_map(static fn (BoardColumn $column): string => $column->getId(), $columns);
        sort($columnIds);
        self::assertSame(['task_board_columns', $columnIds], [$locks[0]['table'], $locks[0]['ids']], 'the remaining columns and the deleted one');
        self::assertSame(['task_board_tasks', [$columns[1]->getId()]], [$locks[1]['table'], $locks[1]['ids']], 'the deleted column\'s tasks');
        self::assertSame(['task_board_tasks', [$columns[0]->getId()]], [$locks[2]['table'], $locks[2]['ids']], 'the target column\'s tasks');
        self::assertGreaterThan($locks[2]['index'], $this->firstWrite($queries));
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

    public function testTwoConcurrentMovesInOneColumnEndWithUniqueContiguousPositions(): void
    {
        [$board, $doing, $tasks] = $this->column();
        $this->requireRowLocks();
        [$low, $middle, $high] = $tasks;
        $entering              = $this->task($board, 'Entering', 0);
        $this->em->clear();
        $entering = $this->em->find(Task::class, $entering->getId());
        self::assertInstanceOf(Task::class, $entering);
        $manager = $this->service(TaskManager::class);

        // The other process holds the column, then moves H to the top (H, L, M). This move read L, M, H before it waited.
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../Fixtures/Deadlock/move_worker.php', 'move', $high->getId(), $doing->getId(), '0', (string) self::OTHER_WAIT], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        // Never read its stderr before it ends: that read would wait for the other process to finish.
        self::assertSame("locked\n", fgets($pipes[1]), 'the other process holds the column');
        $manager->move($entering, $doing->getId(), 1, $entering->getCreator() ?? $entering);
        $other  = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('moved', $other);

        $positions = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT position FROM task_board_tasks WHERE column_id = ? ORDER BY position', [$doing->getId()]));
        self::assertSame([0, 1, 2, 3], $positions, 'unique and contiguous');
        // From the committed column (H, L, M), not from the one read before the lock (L, M, H).
        $this->assertPositions([$high, $entering, $low, $middle]);
    }

    public function testTheLockedReadReturnsTheColumnAsCommittedAndRefreshesStaleCopies(): void
    {
        $board = $this->board('No columns', []);
        $loose = new Task(board: $board, title: 'Loose', creator: $this->owner, position: 0);
        $other = new Task(board: $board, title: 'Other', creator: $this->owner, position: 1);
        $this->em->persist($loose);
        $this->em->persist($other);
        $this->em->flush();
        // Committed by another writer after this copy was loaded.
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET position = 5 WHERE id = ?', [$loose->getId()]);
        $lock = new DoctrineOrmOrderedRowLock($this->em);

        $tasks = $lock->transactional(static fn (): array => $lock->lockedColumnTasks($board->getId(), null, [$other->getId()]));

        $ids = [$loose->getId(), $other->getId()];
        sort($ids);
        self::assertSame($ids, array_map(static fn (Task $task): string => $task->getId(), $tasks), 'each task once, by id');
        self::assertSame(5, $loose->getPosition(), 'the stale copy was refreshed');
        self::assertSame(1, $other->getPosition());
    }

    public function testTwoConcurrentEntriesIntoAColumnGetUniquePositions(): void
    {
        // Audit SF-L3 (probe P2): moveToColumn() took the next free position before the lock.
        $this->requireRowLocks();
        $board = $this->board('Entries');
        $this->task($board, 'Already there', 1, position: 0);
        $p     = $this->task($board, 'P', 0, position: 0);
        $q     = $this->task($board, 'Q', 0, position: 1);
        $doing = $board->getColumns()->get(1);
        self::assertInstanceOf(BoardColumn::class, $doing);
        $this->em->clear();
        $q = $this->em->find(Task::class, $q->getId());
        self::assertInstanceOf(Task::class, $q);
        $manager = $this->service(TaskManager::class);

        [$failure, $other] = $this->withWorker(['enter', $p->getId(), $doing->getId(), (string) self::OTHER_WAIT], static fn () => $manager->moveToColumn($q, $doing->getId(), $q->getCreator() ?? $q));

        self::assertNull($failure, (string) $failure?->getMessage());
        self::assertSame('moved', $other);
        self::assertSame([0, 1, 2], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT position FROM task_board_tasks WHERE column_id = ? ORDER BY position', [$doing->getId()])));
    }

    public function testAColumnDeletionMovesATaskThatEnteredWhileItWaited(): void
    {
        // Audit SF-L4 (probe P3): the deletion moved the tasks it read before the lock; one that entered meanwhile lost
        // its column (ON DELETE SET NULL).
        $this->requireRowLocks();
        $board   = $this->board('Delete race', ['A', 'B', 'C']);
        $columns = array_values($board->getColumns()->toArray());
        $t       = $this->task($board, 'T in C', 2);
        $this->em->clear();
        $deleted = $this->em->find(BoardColumn::class, $columns[0]->getId());
        $owner   = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertInstanceOf(BoardColumn::class, $deleted);
        self::assertNotNull($owner);
        // As a request: the board's tasks loaded before the write.
        $deleted->getBoard()->getTasks()->count();
        $manager = $this->service(BoardColumnManager::class);

        [$failure, $other] = $this->withWorker(['enter', $t->getId(), $deleted->getId(), (string) self::OTHER_WAIT], static fn () => $manager->delete($deleted, $owner));

        self::assertNull($failure, (string) $failure?->getMessage());
        self::assertSame('moved', $other);
        self::assertSame($columns[1]->getId(), $this->em->getConnection()->fetchOne('SELECT column_id FROM task_board_tasks WHERE id = ?', [$t->getId()]));
    }

    public function testAColumnDeletionMovesTheTasksOfTheColumnAsCommitted(): void
    {
        // Audit SF-L4, on every platform: a task put in the column after the board's tasks were loaded moves too.
        $board   = $this->board('Delete committed', ['A', 'B', 'C']);
        $columns = array_values($board->getColumns()->toArray());
        $kept    = $this->task($board, 'In A', 0, position: 0);
        $late    = $this->task($board, 'Late', 2, position: 0);
        $this->em->clear();
        $deleted = $this->em->find(BoardColumn::class, $columns[0]->getId());
        $owner   = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertInstanceOf(BoardColumn::class, $deleted);
        self::assertNotNull($owner);
        $deleted->getBoard()->getTasks()->count();
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET column_id = ?, position = 1 WHERE id = ?', [$deleted->getId(), $late->getId()]);

        self::assertTrue($this->service(BoardColumnManager::class)->delete($deleted, $owner));

        $connection = $this->em->getConnection();
        foreach ([[$kept, 0], [$late, 1]] as [$task, $position]) {
            self::assertSame([$columns[1]->getId(), $position], [$connection->fetchOne('SELECT column_id FROM task_board_tasks WHERE id = ?', [$task->getId()]), (int) $connection->fetchOne('SELECT position FROM task_board_tasks WHERE id = ?', [$task->getId()])]);
        }
    }

    public function testAMoveWhoseTaskChangedColumnMeanwhileIsAConflict(): void
    {
        // Audit SF-L1: the checks (transition policy, history's from-column) were made on the copy read before the lock.
        [$board, $doing] = $this->column();
        $moved           = $this->task($board, 'Moved', 0);
        $done            = $board->getColumns()->get(2);
        self::assertInstanceOf(BoardColumn::class, $done);
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET column_id = ? WHERE id = ?', [$done->getId(), $moved->getId()]);

        try {
            $this->service(TaskManager::class)->moveToColumn($moved, $doing->getId(), $this->owner);
            self::fail('A conflict was expected.');
        } catch (TaskTreeRefusedException $refused) {
            self::assertSame(TaskTreeRefusedException::CONFLICT, $refused->reason);
        }
        self::assertSame($done->getId(), $this->em->getConnection()->fetchOne('SELECT column_id FROM task_board_tasks WHERE id = ?', [$moved->getId()]), 'nothing was written');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_task_change_history WHERE task_id = ? AND change_type = ?', [$moved->getId(), 'column']));
    }

    public function testAnOutOfDateCopyWithUnsavedChangesIsAConflictNotADroppedChange(): void
    {
        // Audit SF-L2 (probe P4): refresh() dropped the caller's change silently.
        $board = $this->board('Pending');
        $task  = $this->task($board, 'Original', 1, position: 0);
        $this->task($board, 'Sibling', 1, position: 1);
        $doing = $board->getColumns()->get(1);
        self::assertInstanceOf(BoardColumn::class, $doing);
        $task->setTitle('Changed by the caller, not saved');
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET position = 7 WHERE id = ?', [$task->getId()]);

        try {
            $this->service(TaskManager::class)->move($task, $doing->getId(), 1, $this->owner);
            self::fail('A conflict was expected.');
        } catch (TaskTreeRefusedException $refused) {
            self::assertSame(TaskTreeRefusedException::CONFLICT, $refused->reason);
        }
        self::assertSame('Changed by the caller, not saved', $task->getTitle(), 'the change is still there');
        self::assertSame(['Original', 7], [$this->em->getConnection()->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$task->getId()]), (int) $this->em->getConnection()->fetchOne('SELECT position FROM task_board_tasks WHERE id = ?', [$task->getId()])]);
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
     * Runs `tests/Fixtures/Deadlock/move_worker.php` (it holds a column, then writes through its own kernel) while
     * `$write` runs here.
     *
     * @param list<string> $args
     * @param callable(): mixed $write
     *
     * @return array{0: Throwable|null, 1: string} the write's failure, and what the worker printed
     */
    private function withWorker(array $args, callable $write): array
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../Fixtures/Deadlock/move_worker.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertTrue(is_resource($process));
        // Never read its stderr before it ends: that read would wait for the other process to finish.
        self::assertSame("locked\n", fgets($pipes[1]), 'the other process holds the column');
        $failure = null;

        try {
            $write();
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
     * The locking reads of the fixed order (`SELECT … ORDER BY id`, with `FOR UPDATE` where the platform has it), in order:
     * the table and the parameters (the ids, or the column of a column's scan).
     *
     * @param list<array{sql: string, params: array<mixed>}> $queries
     *
     * @return list<array{index: int, table: string, ids: list<string>}>
     */
    private function lockReads(array $queries): array
    {
        $locks = [];
        foreach ($queries as $index => $query) {
            if (preg_match('/^SELECT \w+\.id AS \w+.* FROM (task_board_tasks|task_board_columns) \w+ WHERE .* ORDER BY \w+\.id ASC/', $query['sql'], $match) === 1) {
                if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                    self::assertStringContainsString('FOR UPDATE', $query['sql']);
                }
                $locks[] = ['index' => $index, 'table' => $match[1], 'ids' => array_values(array_map('strval', $query['params']))];
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
