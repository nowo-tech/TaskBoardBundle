<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Support\ServerEndsTheTransaction;
use Throwable;

/**
 * Audit PX-L6 (TaskBoard 2.20.0): a sync page and an unlink whose transaction the server ended (a MySQL deadlock) leave
 * the connection at nesting level 0 and a real transaction for the next write of a kept-alive worker.
 */
final class SyncLostTransactionTest extends SyncPeopleFunctionalTestCase
{
    public function testAnUnlinkWhoseTransactionTheServerEndedLeavesTheConnectionClean(): void
    {
        $this->viewer('carol@example.test');
        $this->workspace([self::member('9001', 'carol@example.test')]);
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put($this->row('t1', 'One', 1, creator: self::ref('9001', 'carol@example.test'), assignees: [self::ref('9001', 'carol@example.test')]));
        $this->sync($board);
        $listener = new ServerEndsTheTransaction();
        $this->em->getEventManager()->addEventListener(Events::onFlush, $listener);

        try {
            $this->service()->unlink($this->freshBoard($board), $this->owner);
            self::fail('The unlink must fail.');
        } catch (Throwable) {
        }
        $this->em->getEventManager()->removeEventListener(Events::onFlush, $listener);

        self::assertFalse($listener->armed, 'the unlink flushed');
        $this->assertCleanConnection();
    }

    private function assertCleanConnection(): void
    {
        $connection = $this->em->getConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        // A real transaction again (an in-memory SQLite database is gone with the closed connection: no table to check).
        $connection->beginTransaction();
        $connection->executeQuery('SELECT 1');
        $connection->rollBack();
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }
}
