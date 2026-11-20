<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;
use RuntimeException;
use Throwable;

/**
 * Since 2.28.0 (audit WA-8): a sync page that fails before its flush leaves the manager open. Up to 2.27 the TaskBoard
 * rows the page had created stayed scheduled in it, so the next flush (the run's own status write, or the application's)
 * inserted them after the page's rollback. They now leave the manager, as after a dry run; the application's entities
 * and their pending changes stay.
 */
final class SyncFailedPageTest extends SyncFunctionalTestCase
{
    public function testAPageThatFailsBeforeItsFlushLeavesNoTaskBoardRowScheduled(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private manager is cleared after a failed page (it is the bundle\'s own).');
        }
        $board = $this->board('Remote');
        $this->put($this->remote('r1', 'Ghost one'), $this->remote('r2', 'Ghost two'));
        $failing = new class {
            public bool $armed = true;

            public function preFlush(PreFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($this->armed && $entity instanceof Task) {
                        $this->armed = false;

                        throw new RuntimeException('The page fails before its flush writes anything.');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::preFlush, $failing);
        $bystander = $this->user('bystander@example.com');
        $bystander->setDisplayName('Pending application change');

        try {
            $this->sync($board);
        } catch (Throwable) {
            // The run reports the failure, or rethrows it: either way nothing of the page may be written later.
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::preFlush, $failing);
        }

        self::assertFalse($failing->armed, 'the page failed');
        self::assertTrue($this->em->isOpen());
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            self::assertFalse(TaskBoardManagerRegistry::isBundleEntity($entity::class), $entity::class . ' is still scheduled for insertion');
        }
        self::assertTrue($this->em->contains($bystander), 'the application\'s entities stay managed');

        $this->em->flush();

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $this->em->getClassMetadata(Task::class)->getTableName() . ' WHERE title LIKE ?', ['Ghost%']), 'no ghost task after the rollback');
        self::assertSame('Pending application change', $connection->fetchOne('SELECT display_name FROM ' . $this->em->getClassMetadata(AppUser::class)->getTableName() . ' WHERE email = ?', ['bystander@example.com']), 'the application\'s change is kept');
    }
}
