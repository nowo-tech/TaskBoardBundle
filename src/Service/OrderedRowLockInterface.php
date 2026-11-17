<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Closure;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.21.0 (Beacon spec 239, block B, FR-013): the fixed lock order of the bundle's multi-row writes. A write that
 * changes several rows first locks every row it will write, columns before tasks, each in ascending id order, inside one
 * transaction; the writes that follow (in whatever order the persistence layer issues them) then never wait on a row a
 * concurrent write of the same kind holds while it waits on one of ours, so they cannot deadlock (MySQL 1213) against
 * each other. Positions and every written value stay exactly as without it.
 *
 * The bundle's implementation is `Repository\DoctrineOrmOrderedRowLock` (on `database.entity_manager`, or the private
 * manager); a host or a test may double it, and a service built without one writes as before (no transaction of its own,
 * no locks). Plain PHP: a closure, ids and bundle entities only. Implementations keep no state between calls (FrankenPHP worker mode,
 * kernel reset false).
 *
 * @internal an SPI of the bundle's own domain services; a host depends on their ports
 */
interface OrderedRowLockInterface
{
    /**
     * Runs `$work` in one transaction: committed when it returns, rolled back when it throws. Inside a caller's
     * transaction it joins it (no savepoint): the caller owns the outcome, and the locks stay until its commit. A
     * failure, a lock conflict included, reaches the caller as it is (a MySQL deadlock stays the driver's deadlock
     * exception, so a host that retries 1213 / 1205 keeps doing so).
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function transactional(Closure $work): mixed;

    /**
     * Locks the board columns' rows, in ascending id order, until the transaction ends (`SELECT … ORDER BY id FOR
     * UPDATE`; a no-op where the platform serialises writers, as SQLite does). Ids that do not exist are ignored. Inside
     * {@see self::transactional()} only, before the tasks are locked.
     *
     * @param list<string> $columnIds
     */
    public function lockColumns(array $columnIds): void;

    /**
     * Locks the tasks' rows like {@see self::lockColumns()}, in ascending id order. Inside {@see self::transactional()}
     * only, after the columns.
     *
     * @param list<string> $taskIds
     */
    public function lockTasks(array $taskIds): void;

    /**
     * The tasks of a board's column (`null`: the tasks without one), active and completed, not archived, as committed
     * now: each row is locked like {@see self::lockTasks()} (the locking read sees the last committed rows, whatever
     * the transaction read before), and a task whose loaded copy is out of date (position, column or revision) is
     * refreshed before it is returned. The tasks of `$alsoTaskIds` (already locked, e.g. a task entering the column)
     * are brought up to date the same way and returned too. Inside {@see self::transactional()} only, after the column
     * is locked. Since 2.21.0: a write that waited for a concurrent one computes from that one's values.
     *
     * @param list<string> $alsoTaskIds
     *
     * @return list<Task> by id
     */
    public function lockedColumnTasks(string $boardId, ?string $columnId, array $alsoTaskIds = []): array;
}
