<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Since 2.20.0 (audits PX-M1, PX-L6): the one way the bundle rolls back a transaction it opened. It rolls back to the
 * level the caller entered at. When that fails because the server already ended the transaction (a MySQL / MariaDB
 * deadlock removes the savepoint, so `ROLLBACK TO SAVEPOINT` fails and DBAL keeps its nesting level), the connection is
 * closed at the outermost level: its nesting level goes back to 0 and the next query reconnects, so a kept-alive worker
 * never writes outside a transaction it believes open. Inside a caller's transaction the caller owns it and nothing is
 * closed. It never throws: the caller rethrows the write's own failure.
 *
 * @internal
 */
final class TransactionRollback
{
    public static function toLevel(Connection $connection, int $level): void
    {
        try {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            if ($level === 0) {
                $connection->close();
            }
        }
    }
}
