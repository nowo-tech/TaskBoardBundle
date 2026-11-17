<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Closure;

/**
 * Since 2.21.0 (Beacon spec 239, block B, FR-013): the fixed lock order of the bundle's multi-row writes. A write that
 * changes several rows first locks every row it will write, columns before tasks, each in ascending id order, inside one
 * transaction; the writes that follow (in whatever order the persistence layer issues them) then never wait on a row a
 * concurrent write of the same kind holds while it waits on one of ours, so they cannot deadlock (MySQL 1213) against
 * each other. Positions and every written value stay exactly as without it.
 *
 * The bundle's implementation is `Repository\DoctrineOrmOrderedRowLock` (on `database.entity_manager`, or the private
 * manager); a host or a test may double it, and a service built without one writes as before (no transaction of its own,
 * no locks). Plain PHP: a closure and ids only. Implementations keep no state between calls (FrankenPHP worker mode,
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
}
