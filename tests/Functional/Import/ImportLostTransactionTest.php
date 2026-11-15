<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Import;

use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\Tests\Support\ServerEndsTheTransaction;
use Throwable;

/**
 * Audit PX-L6 (TaskBoard 2.20.0): an import whose transaction the server ended (a MySQL deadlock) leaves the connection
 * at nesting level 0, so the next write of a kept-alive worker is a real transaction again.
 */
final class ImportLostTransactionTest extends FunctionalTestCase
{
    public function testTheConnectionIsCleanAfterTheServerEndedTheImportsTransaction(): void
    {
        $board  = $this->board();
        $import = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $import);
        $listener = new ServerEndsTheTransaction();
        $this->em->getEventManager()->addEventListener(Events::onFlush, $listener);

        try {
            $import->import($board, TaskImportSource::TaskBoardCsv, "id,parent,title\na,,Lost\n", 'plan.csv', $this->owner);
            self::fail('The import must fail.');
        } catch (Throwable) {
        }
        $this->em->getEventManager()->removeEventListener(Events::onFlush, $listener);

        $connection = $this->em->getConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        // A real transaction again (an in-memory SQLite database is gone with the closed connection: no table to check).
        $connection->beginTransaction();
        $connection->executeQuery('SELECT 1');
        $connection->rollBack();
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }
}
