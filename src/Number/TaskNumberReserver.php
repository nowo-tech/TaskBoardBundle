<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use RuntimeException;

use function sprintf;

/**
 * Since 2.1.0 (spec 214, FR-003): hands out numbers per scope in one short transaction of its own, so a long sync page never
 * holds a counter. When the caller is already in a transaction (a sync or import page) the reservation commits on a second
 * connection of the same database. A write that fails afterwards leaves a gap, never a duplicate.
 *
 * SQLite is the exception: it allows one writer, so a second connection would wait for the first. There the counter joins the
 * open transaction, and nothing else can write meanwhile.
 *
 * The second connection waits for a lock only briefly. If the caller's own transaction holds the counter (a fixture purge deletes
 * the table, a tenant purged in the same transaction) it would otherwise wait for itself until the database gives up. On that, or
 * on any failure to reach the database a second time, the counter joins the caller's transaction: slower for others, never a duplicate.
 */
final readonly class TaskNumberReserver
{
    /** A first number races at most once per other process creating the same row. */
    private const MAX_ATTEMPTS = 3;

    /** A counter is held for milliseconds: a longer wait means the caller holds it itself. */
    private const LOCK_WAIT_SECONDS = 2;

    /**
     * @param array<string, int> $counts how many numbers per scope key
     *
     * @return array<string, int> the first number of each scope's range
     */
    public function reserve(Connection $connection, array $counts): array
    {
        $own = $this->ownConnection($connection);

        try {
            $first = [];
            foreach ($counts as $scope => $count) {
                $first[$scope] = $this->reserveWith($own, $connection, $scope, $count);
            }

            return $first;
        } finally {
            $own?->close();
        }
    }

    private function reserveWith(?Connection $own, Connection $caller, string $scope, int $count): int
    {
        if ($own instanceof Connection) {
            try {
                return $this->reserveOn($own, $scope, $count);
            } catch (DbalException) {
                // Below, in the caller's transaction.
            }
        }

        return $this->reserveOn($caller, $scope, $count);
    }

    private function ownConnection(Connection $connection): ?Connection
    {
        if (!$connection->isTransactionActive() || $connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return null;
        }

        try {
            $own      = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
            $platform = $own->getDatabasePlatform();
            $limit    = $platform instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS : ($platform instanceof PostgreSQLPlatform ? "SET lock_timeout = '" . self::LOCK_WAIT_SECONDS . "s'" : null);
            if ($limit !== null) {
                $own->executeStatement($limit);
            }

            return $own;
        } catch (DbalException) {
            return null;
        }
    }

    private function reserveOn(Connection $connection, string $scope, int $count): int
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $first = $connection->transactional(static function (Connection $db) use ($scope, $count): ?int {
                $updated = $db->executeStatement(sprintf('UPDATE %s SET last_number = last_number + ? WHERE scope_key = ?', TaskNumberCounter::TABLE), [$count, $scope]);
                if ($updated === 0) {
                    return null;
                }
                $row = $db->fetchAssociative(sprintf('SELECT scope_key, last_number FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), [$scope]);
                // Since 2.15.1 (REQ-SEC-006 items 2 and 6): on a column that still folds (before the UPGRADING ALTER) the
                // counter found may be a look-alike scope's (`Acme` for `acme`): fail closed, the update is rolled back.
                if ($row === false || $row['scope_key'] !== $scope) {
                    throw TaskNumberScopeConflictException::forScope($scope);
                }

                return (int) $row['last_number'] - $count + 1;
            });
            if ($first !== null) {
                return $first;
            }

            // Outside the transaction above: on PostgreSQL a duplicate key would have aborted it.
            try {
                $connection->insert(TaskNumberCounter::TABLE, ['scope_key' => $scope, 'last_number' => $count]);

                return 1;
            } catch (UniqueConstraintViolationException) {
                // Another process created the row first: its counter is there now.
            }
        }

        throw new RuntimeException(sprintf('Could not reserve task numbers in scope "%s".', $scope));
    }
}
