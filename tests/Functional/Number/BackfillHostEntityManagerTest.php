<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use Nowo\TaskBoardBundle\Number\TaskNumberBackfiller;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;

use function sprintf;

/**
 * Since 2.26.1 (REQ-RUNTIME-003 rule 2): a backfill batch on the application's entity manager (the 2.x default) detaches
 * the tasks it loaded, never the application's entities, and leaves the application's pending changes pending (the
 * batch writes with plain SQL and never flushes the manager).
 */
final class BackfillHostEntityManagerTest extends TaskNumberTestCase
{
    public function testABatchKeepsTheApplicationEntitiesAndTheirPendingChanges(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private-manager replay turns the option on.');
        }
        $host = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertSame($host, $this->em);
        $board = $this->board('One', tenantKey: 'acme');
        $task  = $this->task($board, 'old');
        $db    = $host->getConnection();
        $db->executeStatement(sprintf('UPDATE %s SET number = NULL WHERE id = ?', $host->getClassMetadata(Task::class)->getTableName()), [$task->getId()]);
        $db->executeStatement('DELETE FROM ' . TaskNumberEntry::TABLE);
        $db->executeStatement('DELETE FROM ' . TaskNumberCounter::TABLE);
        $bystander = $this->user('bystander@example.com');
        $bystander->setDisplayName('Edited by the application');
        $added = new AppUser('added@example.com');
        $host->persist($added);

        $backfiller = self::getContainer()->get(TaskNumberBackfiller::class);
        self::assertInstanceOf(TaskNumberBackfiller::class, $backfiller);
        self::assertSame(1, $backfiller->runBatch(10));

        self::assertTrue($host->isOpen());
        self::assertFalse($host->contains($task), 'the stale task leaves the manager');
        self::assertFalse($host->contains($board));
        self::assertTrue($host->contains($this->owner), 'the application entities stay managed');
        self::assertTrue($host->contains($bystander));
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($added), 'the pending insert is still pending');
        $host->getUnitOfWork()->computeChangeSets();
        self::assertArrayHasKey('displayName', $host->getUnitOfWork()->getEntityChangeSet($bystander), 'the pending update is still pending');

        $host->flush();
        $users = $host->getClassMetadata(AppUser::class)->getTableName();
        self::assertSame('Edited by the application', $db->fetchOne('SELECT display_name FROM ' . $users . ' WHERE email = ?', ['bystander@example.com']));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $users . ' WHERE email = ?', ['added@example.com']));
        self::assertSame(1, $host->find(Task::class, $task->getId())?->getNumber());
    }
}
