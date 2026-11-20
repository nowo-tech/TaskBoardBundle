<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\HostCascadeLink;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\HostProject;
use Nowo\TaskBoardBundle\Tests\Support\PrivateEntityManagerReplay;

use function array_map;
use function implode;
use function md5;
use function substr;

/**
 * Since 2.26.1 (audits WA-1, WA-2): without `database.private_entity_manager` the sync shares the application's entity
 * manager. TaskBoard rows that application entities reference stay managed across the sync's pages, its dry run and its
 * reloads, so the run finishes and the application's next flush works; application rows written inside a rolled-back
 * dry-run page leave the manager. Run on SQLite, MySQL, MariaDB and PostgreSQL.
 */
final class SyncHostReferencesTest extends SyncFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (PrivateEntityManagerReplay::enabled()) {
            self::markTestSkipped('The private-manager replay turns the option on: the application manager is not shared.');
        }
    }

    /** Probe A: one page; the run finishes and the application's later edit is written. */
    public function testAnApplicationEntityReferencingTheBoard(): void
    {
        $board   = $this->board('Remote');
        $project = $this->project(board: $board);
        $this->put($this->remote('r1', 'One'), $this->remote('r2', 'Two'));

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertTrue($this->em->contains($project));
        self::assertTrue($this->em->contains($board), 'the referenced board stays managed');
        $this->assertFlushWrites($project);
        self::assertSame(2, $this->rows(Task::class));
        self::assertSame(['succeeded:finished'], $this->runs());
        self::assertNotNull($this->connection()?->getLastSuccessAt(), 'the watermark is saved');
    }

    /** Probe B: two pages (150 tasks, pages of 100). */
    public function testAnApplicationEntityReferencingTheBoardAcrossPages(): void
    {
        $board   = $this->board('Remote');
        $project = $this->project(board: $board);
        $remotes = [];
        for ($i = 1; $i <= 150; ++$i) {
            $remotes[] = $this->remote('r' . $i, 'T' . $i);
        }
        $this->put(...$remotes);

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, 'errors: ' . implode(',', array_map(static fn (SyncError $e): string => $e->code, $report->errors)));
        self::assertSame(2, $report->pagesDone);
        self::assertSame(150, $this->rows(Task::class));
        self::assertSame(['succeeded:finished'], $this->runs());
        $this->assertFlushWrites($project);
    }

    /** Probe C: the application entity references a task; the task keeps its board and column. */
    public function testAnApplicationEntityReferencingATask(): void
    {
        $board   = $this->board('Remote');
        $local   = $this->task($board, 'Local');
        $project = $this->project(task: $local);
        $this->put($this->remote('r1', 'One'));

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertTrue($this->em->contains($local));
        self::assertTrue($this->em->contains($local->getBoard()), 'a kept task keeps its board');
        self::assertTrue($this->em->contains($local->getColumn()), 'and its column');
        $this->assertFlushWrites($project);
        self::assertSame(['succeeded:finished'], $this->runs());
    }

    /** Probe D: cascade persist from the application entity to the task. */
    public function testAnApplicationEntityCascadingPersistToATask(): void
    {
        $board      = $this->board('Remote');
        $local      = $this->task($board, 'Local');
        $link       = new HostCascadeLink();
        $link->task = $local;
        $this->em->persist($link);
        $this->em->flush();
        $this->put($this->remote('r1', 'One'));

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status);
        $link->name = 'changed after the sync';
        $this->em->flush();
        self::assertTrue($this->em->isOpen());
        self::assertSame(1, $this->rows(Task::class, 'title = ?', ['Local']), 'the task is never inserted again');
        self::assertSame('changed after the sync', $this->em->getConnection()->fetchOne('SELECT name FROM app_host_cascade_link'));
    }

    /** Probe E: a dry run that creates a column and a task on a board an application entity references. */
    public function testADryRunWithAnApplicationEntityReferencingTheBoard(): void
    {
        $board   = $this->board('Remote');
        $project = $this->project(board: $board);
        $this->put($this->remote('r1', 'One', status: 'Review'));

        $report = $this->sync($board, dryRun: true);

        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame(1, $report->created);
        self::assertTrue($this->em->contains($board));
        self::assertCount(3, $board->getColumns(), 'the kept board was read again: no column of the rolled-back page');
        $this->assertFlushWrites($project);
        self::assertSame(0, $this->rows(Task::class));
        self::assertSame(3, $this->rows(BoardColumn::class), 'the column of the dry run is not saved');
        self::assertSame(['dry:succeeded:finished'], $this->runs());
    }

    /** Probe I: an application onFlush listener writes a row per new task; the dry run rolls the page back. */
    public function testADryRunForgetsTheApplicationRowsOfTheRolledBackPage(): void
    {
        $board = $this->board('Remote');
        $this->put($this->remote('r1', 'One'));
        $kept = $this->user('kept@example.com');
        $kept->setDisplayName('before');
        $this->em->flush();
        $listener = new class($kept) {
            /** @var list<AppUser> */
            public array $created = [];

            public function __construct(private readonly AppUser $kept)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                $em         = $args->getObjectManager();
                $unitOfWork = $em->getUnitOfWork();
                foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof Task) {
                        $user = new AppUser('listener-' . substr(md5($entity->getTitle()), 0, 8) . '@example.com');
                        $em->persist($user);
                        $unitOfWork->computeChangeSet($em->getClassMetadata(AppUser::class), $user);
                        $this->created[] = $user;
                        $this->kept->setDisplayName('changed by the listener');
                        $unitOfWork->recomputeSingleEntityChangeSet($em->getClassMetadata(AppUser::class), $this->kept);
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $listener);
        try {
            $report = $this->sync($board, dryRun: true);
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $listener);
        }

        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertCount(1, $listener->created);
        foreach ($listener->created as $user) {
            self::assertFalse($this->em->contains($user), 'a row the rollback removed leaves the manager');
        }
        self::assertTrue($this->em->contains($kept));
        self::assertSame('before', $kept->getDisplayName(), 'a row the rollback restored is read again');
        self::assertSame(0, $this->rows(AppUser::class, 'email LIKE ?', ['listener-%']));
        $kept->setDisplayName('edited later');
        $this->em->flush();
        self::assertSame('edited later', $this->em->getConnection()->fetchOne('SELECT display_name FROM app_user WHERE email = ?', ['kept@example.com']));
    }

    private function project(?TaskBoard $board = null, ?Task $task = null): HostProject
    {
        $project        = new HostProject();
        $project->board = $board;
        $project->task  = $task;
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function assertFlushWrites(HostProject $project): void
    {
        $project->name = 'changed after the sync';
        $this->em->flush();
        self::assertTrue($this->em->isOpen());
        self::assertSame('changed after the sync', $this->em->getConnection()->fetchOne('SELECT name FROM app_host_project WHERE id = ?', [$project->id]));
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
            $runs[] = ($run->isDryRun() ? 'dry:' : '') . $run->getStatus()->value . ($run->getFinishedAt() instanceof DateTimeImmutable ? ':finished' : ':unfinished');
        }

        return $runs;
    }

    private function connection(): ?TaskBoardConnection
    {
        $connection = $this->em->getRepository(TaskBoardConnection::class)->findOneBy([]);
        if ($connection instanceof TaskBoardConnection) {
            $this->em->refresh($connection);
        }

        return $connection;
    }
}
