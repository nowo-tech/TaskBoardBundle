<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardManagerRegistry;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\HostProject;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;
use RuntimeException;
use Throwable;

/**
 * Since 2.28.0 (audit WA-8): a sync page that fails before its flush leaves the manager open. Up to 2.27 the TaskBoard
 * rows the page had created stayed scheduled in it, so the next flush (the run's own status write, or the application's)
 * inserted them after the page's rollback. They now leave the manager, as after a dry run; the application's entities
 * and their pending changes stay. The TaskBoard rows an application entity still references stay managed and are read
 * again, so none of them keeps a value or a collection element the rollback undid (audit XP-1).
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

    /** Audit XP-1: a page that fails before its flush; the board an application entity references is read again. */
    public function testAReferencedBoardIsReadAgainAfterAPageThatFailsBeforeItsFlush(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private manager is cleared after a failed page (it is the bundle\'s own).');
        }
        $board   = $this->board('Remote');
        $project = $this->project($board, null);
        // "Review" is no column of the board: the page creates it, then fails before its flush.
        $this->put($this->remote('r1', 'Ghost', status: 'Review'));
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
        $project->name = 'Pending application change';

        try {
            $report = $this->sync($board);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::preFlush, $failing);
        }

        self::assertFalse($failing->armed, 'the page failed');
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($board), 'the referenced board stays managed');
        self::assertCount(3, $board->getColumns(), 'no column of the rolled-back page on the kept board');

        $this->em->flush();

        self::assertSame('Pending application change', $this->em->getConnection()->fetchOne('SELECT name FROM app_host_project WHERE id = ?', [$project->id]), 'the application\'s change is kept');
        self::assertSame(0, $this->rows(Task::class, 'title = ?', ['Ghost']));
        self::assertSame(3, $this->rows(BoardColumn::class), 'the page\'s column is never written');
        self::assertSame(['failed:finished'], $this->runs());
        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status, 'a later run works');
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Ghost']));
    }

    /**
     * Audit XP-1: a page that fails after its flush with the manager still open (as when its commit fails); the task an
     * application entity references is read again, so the application's next flush does not write the page's change.
     */
    public function testAReferencedTaskIsReadAgainAfterAPageThatFailsAfterItsFlush(): void
    {
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private manager is cleared after a failed page (it is the bundle\'s own).');
        }
        $board = $this->board('Remote');
        $this->put($this->remote('r1', 'Original'));
        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status);
        $task    = $this->taskTitled('Original');
        $project = $this->project(null, $task);
        $this->put($this->remote('r1', 'Changed by the failed page', updated: 50), $this->remote('r2', 'Ghost', status: 'Review'));
        $failing = new class {
            public bool $armed = true;

            public function postFlush(PostFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getIdentityMap()[Task::class] ?? [] as $entity) {
                    if ($this->armed && $entity instanceof Task && $entity->getTitle() === 'Ghost') {
                        $this->armed = false;

                        throw new RuntimeException('The page fails after its flush, before its commit.');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::postFlush, $failing);
        $project->name = 'Pending application change';

        try {
            $report = $this->sync($board);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::postFlush, $failing);
        }

        self::assertFalse($failing->armed, 'the page failed after its flush');
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($task), 'the referenced task stays managed');
        self::assertSame('Original', $task->getTitle(), 'the referenced task was read again after the rollback');

        $this->em->flush();

        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Original']), 'the rolled-back title is never written');
        self::assertSame(0, $this->rows(Task::class, 'title = ?', ['Ghost']));
        self::assertSame(3, $this->rows(BoardColumn::class));
        self::assertSame('Pending application change', $this->em->getConnection()->fetchOne('SELECT name FROM app_host_project WHERE id = ?', [$project->id]));
        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status, 'a later run works');
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Ghost']));
    }

    private function project(?TaskBoard $board, ?Task $task): HostProject
    {
        $project        = new HostProject();
        $project->board = $board;
        $project->task  = $task;
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @param list<mixed> $parameters
     */
    private function rows(string $class, string $where = '1 = 1', array $parameters = []): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->em->getClassMetadata($class)->getTableName() . ' WHERE ' . $where, $parameters);
    }

    /**
     * @return list<string>
     */
    private function runs(): array
    {
        $runs = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id FROM ' . $this->em->getClassMetadata(TaskSyncRun::class)->getTableName()) as $row) {
            $run = $this->em->find(TaskSyncRun::class, $row['id']);
            self::assertInstanceOf(TaskSyncRun::class, $run);
            $this->em->refresh($run);
            $runs[] = $run->getStatus()->value . ($run->getFinishedAt() instanceof DateTimeImmutable ? ':finished' : ':unfinished');
        }

        return $runs;
    }
}
