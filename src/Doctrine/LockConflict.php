<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Throwable;

use function str_contains;

/**
 * Since 2.20.0 (extracted from `TaskMover`, audits IC-I9 / IC-I9r): whether a failure is a lock conflict with a concurrent
 * write, so the database wrote nothing and the write can be offered again:
 *  - Doctrine's deadlock and lock-wait-timeout exceptions (MySQL 1213 / 1205, PostgreSQL 40001 / 40P01, SQLite "locked");
 *  - PostgreSQL `lock_not_available` (SQLSTATE 55P03, raised by `lock_timeout`), which Doctrine DBAL does not map;
 *  - MySQL 1305 on a savepoint: InnoDB rolls back the whole transaction on a deadlock (and on a lock wait timeout with
 *    `innodb_rollback_on_timeout`), so the flush's `ROLLBACK TO SAVEPOINT` fails and its error replaces the deadlock.
 *    The savepoint is gone because the server ended the transaction, normally by rolling it back (audit IG-I1: host
 *    code that commits implicitly inside the flush, such as DDL, would also end it);
 *  - since 2.22.0 (audit SU-I2), MariaDB 1020 ("Record has changed since last read", a snapshot isolation conflict), which
 *    nowo-tech/transaction-doctrine classifies with the deadlocks: nothing was written, the write can be offered again.
 *
 * @internal
 */
final class LockConflict
{
    public static function is(Throwable $failure): bool
    {
        if ($failure instanceof DeadlockException || $failure instanceof LockWaitTimeoutException) {
            return true;
        }
        if (!$failure instanceof DriverException) {
            return false;
        }

        return $failure->getSQLState() === '55P03' || $failure->getCode() === self::MARIADB_SNAPSHOT_CONFLICT || self::isLostSavepoint($failure);
    }

    /** MariaDB's snapshot isolation conflict (since 2.22.0). */
    public const MARIADB_SNAPSHOT_CONFLICT = 1020;

    /**
     * Since 2.22.0 (audit SU-I1): MySQL 1305 on a savepoint, the server ended the transaction behind the flush's back. Seen
     * alone (no deadlock or lock wait timeout in the chain), what ended it is unknown — it may have been committed (DDL, an
     * implicit commit) — so a client must read again rather than send the same request.
     */
    public static function isLostSavepoint(Throwable $failure): bool
    {
        return $failure instanceof DriverException && $failure->getCode() === 1305 && str_contains($failure->getMessage(), 'SAVEPOINT');
    }
}
