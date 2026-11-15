<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support;

use Doctrine\ORM\Event\OnFlushEventArgs;
use RuntimeException;

/**
 * Audits PX-M1 / PX-L6: an `onFlush` listener that does what InnoDB does on a deadlock: the server ends the whole
 * transaction (a raw `ROLLBACK`, so every savepoint is gone while DBAL still counts its levels), then the write fails.
 * The bundle's own `ROLLBACK` afterwards fails, as on MySQL. Armed once, for a flush inside a transaction.
 */
final class ServerEndsTheTransaction
{
    public bool $armed = true;

    /** @var list<int> the nesting level of every flush seen */
    public array $levels = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $connection     = $args->getObjectManager()->getConnection();
        $this->levels[] = $connection->getTransactionNestingLevel();
        // Only a flush inside a transaction the bundle opened (`onFlush` runs before the flush opens its own).
        if (!$this->armed || $connection->getTransactionNestingLevel() < 1) {
            return;
        }
        $this->armed = false;
        $connection->executeStatement('ROLLBACK');

        throw new RuntimeException('Deadlock found when trying to get lock (simulated: the server ended the transaction)');
    }
}
