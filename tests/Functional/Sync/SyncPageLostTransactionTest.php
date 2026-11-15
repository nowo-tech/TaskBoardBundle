<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Tests\Support\ServerEndsTheTransaction;
use Throwable;

use function implode;

/**
 * Audit PX-L6 (TaskBoard 2.20.0): a sync page whose transaction the server ended (a MySQL deadlock) leaves the connection
 * at nesting level 0 and a real transaction for the next write of a kept-alive worker.
 */
final class SyncPageLostTransactionTest extends SyncFunctionalTestCase
{
    public function testASyncPageWhoseTransactionTheServerEndedLeavesTheConnectionClean(): void
    {
        $board = $this->board();
        $this->put($this->remote('t1', 'One'));
        $listener = new ServerEndsTheTransaction();
        $this->em->getEventManager()->addEventListener(Events::onFlush, $listener);

        try {
            $this->sync($board);
        } catch (Throwable) {
            // The run reports the failure, or rethrows it: either way the connection must be clean.
        }
        $this->em->getEventManager()->removeEventListener(Events::onFlush, $listener);

        self::assertFalse($listener->armed, 'the page wrote: ' . implode(',', $listener->levels));
        $connection = $this->em->getConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        // A real transaction again (an in-memory SQLite database is gone with the closed connection: no table to check).
        $connection->beginTransaction();
        $connection->executeQuery('SELECT 1');
        $connection->rollBack();
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }
}
