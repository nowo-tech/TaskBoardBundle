<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

use function sprintf;

/**
 * Since 2.17.0 (audit PC-L4): `revision = revision + 1` on one task row, answering the value written, in one statement
 * where the database allows it (so a write adds no read to a batch's query budget):
 *
 * - PostgreSQL, SQLite (3.35+): `UPDATE … RETURNING`;
 * - MySQL, MariaDB: `SET revision = LAST_INSERT_ID(revision + 1)`, then the driver's insert id of this connection (no
 *   query; the value is this connection's own, never another session's);
 * - any other platform: the UPDATE, then a SELECT of the row this transaction has just written.
 *
 * A row another request deleted during the write is null on every platform (audit PG-L1): no increment, no error.
 *
 * Called inside the flush's transaction, after the row's own UPDATE or as its first write, so the row stays locked until
 * the commit. Stateless.
 *
 * @internal
 */
final class TaskRevisionIncrement
{
    /**
     * @param string $table the quoted table name
     * @param string $column the quoted revision column
     * @param string $idColumn the quoted identifier column
     *
     * @return int|null the revision written; null when the row is gone (audit PG-L1: another request deleted the task
     *                  during this write; the same on every platform, nothing incremented)
     */
    public static function next(Connection $connection, string $table, string $column, string $idColumn, string $id): ?int
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            $revision = $connection->fetchOne(sprintf('UPDATE %1$s SET %2$s = %2$s + 1 WHERE %3$s = ? RETURNING %2$s', $table, $column, $idColumn), [$id]);

            return $revision === false ? null : (int) $revision;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            // The increment always changes the value, so 0 affected rows is a row gone (with or without CLIENT_FOUND_ROWS).
            if ((int) $connection->executeStatement(sprintf('UPDATE %1$s SET %2$s = LAST_INSERT_ID(%2$s + 1) WHERE %3$s = ?', $table, $column, $idColumn), [$id]) === 0) {
                return null;
            }

            return (int) $connection->lastInsertId();
        }
        if ((int) $connection->executeStatement(sprintf('UPDATE %1$s SET %2$s = %2$s + 1 WHERE %3$s = ?', $table, $column, $idColumn), [$id]) === 0) {
            return null;
        }
        $revision = $connection->fetchOne(sprintf('SELECT %s FROM %s WHERE %s = ?', $column, $table, $idColumn), [$id]);

        return $revision === false ? null : (int) $revision;
    }
}
