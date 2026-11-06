<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Event\UnmatchedColumnsResolvedEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\People\PlainTaskProvider;

use function array_map;
use function array_values;
use function json_encode;
use function sort;

/**
 * Since 1.28.0 (TB-SYNC-4): `unmatchedColumns`. The agreed safeguards, one by one.
 */
final class UnmatchedColumnsTest extends SyncPeopleFunctionalTestCase
{
    /** @param array<mixed> $map */
    private function linked(TaskBoard $board, string $choice = 'delete', array $map = []): TaskBoardConnection
    {
        return $this->service()->link(
            $board,
            $this->provider,
            new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'),
            new RemoteContainer(self::LIST, 'List', ContainerType::List, null, true, false),
            $this->owner,
            ['unmatchedColumns' => $choice],
            $map,
        );
    }

    /**
     * @param list<string> $names
     */
    private function statuses(array $names): void
    {
        $this->provider->withStatuses(self::LIST, array_map(static fn (string $name): RemoteStatus => new RemoteStatus($name, null, null, 0), $names));
    }

    /**
     * @return list<string>
     */
    private function columnNames(TaskBoard $board): array
    {
        $this->em->clear();
        $this->refreshOwner();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $names = array_map(static fn (BoardColumn $column): string => $column->getName(), array_values($fresh->getColumns()->toArray()));
        sort($names);

        return $names;
    }

    private function codes(SyncReport $report): string
    {
        return (string) json_encode(array_map(static fn (SyncError $warning): array => [$warning->code, $warning->params], $report->warnings));
    }

    public function testEmptyUnmatchedColumnsGoAtTheEndOfTheFirstImportAndLaterSyncsDeleteNothing(): void
    {
        $board = $this->boardWithColumns('To do', 'Spare', 'Doing', 'Done');
        $this->statuses(['Doing', 'Done']);
        $this->linked($board);
        $this->put($this->row('t1', 'One', 1, status: 'Doing'));
        $events = [];
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_UNMATCHED_COLUMNS_RESOLVED, static function (UnmatchedColumnsResolvedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $report = $this->sync($board);

        self::assertSame(SyncStatus::Succeeded, $report->status, $this->codes($report));
        self::assertSame(['Doing', 'Done'], $this->columnNames($board), 'a mapped column stays with zero tasks; both unmatched empty ones go');
        self::assertSame(2, $report->stats['columns.unmatchedDeleted'] ?? null);
        self::assertSame(0, $report->stats['columns.unmatchedKept'] ?? null);
        self::assertStringContainsString('unmatched_columns_deleted', $this->codes($report));
        self::assertCount(1, $events);
        self::assertCount(2, $events[0]->deleted);
        self::assertSame($report->runId, $events[0]->runId);

        // Positions are continuous, as after Delete column.
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $positions = array_map(static fn (BoardColumn $column): int => $column->getPosition(), array_values($fresh->getColumns()->toArray()));
        sort($positions);
        self::assertSame([0, 1], $positions);

        // A column added by hand later survives every later sync.
        $fresh->addColumn(new BoardColumn($fresh, 'By hand', 2));
        $this->em->flush();
        $this->sync($board, full: true);
        $this->sync($board);
        self::assertContains('By hand', $this->columnNames($board));
        self::assertCount(1, $events, 'acts once per connection');
    }

    public function testAColumnWithAnyTaskRowIsKeptAndListedAndTasksNeverMove(): void
    {
        $board = $this->boardWithColumns('Archived', 'Completed', 'Active', 'Empty', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $byName = [];
        foreach ($board->getColumns() as $column) {
            $byName[$column->getName()] = $column;
        }
        $archived  = (new Task(board: $board, title: 'A', creator: $this->owner, column: $byName['Archived']))->archive();
        $completed = (new Task(board: $board, title: 'C', creator: $this->owner, column: $byName['Completed']))->markCompleted();
        $active    = new Task(board: $board, title: 'B', creator: $this->owner, column: $byName['Active']);
        foreach ([$archived, $completed, $active] as $task) {
            $this->em->persist($task);
        }
        $this->em->flush();
        $ids = ['Archived' => $archived->getId(), 'Completed' => $completed->getId(), 'Active' => $active->getId()];

        $report = $this->sync($board);

        self::assertSame(['Active', 'Archived', 'Completed', 'Doing'], $this->columnNames($board));
        self::assertSame(1, $report->stats['columns.unmatchedDeleted'] ?? null);
        self::assertSame(3, $report->stats['columns.unmatchedKept'] ?? null);
        foreach ($ids as $column => $id) {
            $task = $this->em->find(Task::class, $id);
            self::assertInstanceOf(Task::class, $task);
            self::assertSame($column, $task->getColumn()?->getName(), 'never moved');
            self::assertStringContainsString('unmatched_column_kept_tasks', $this->codes($report));
            self::assertStringContainsString($column, $this->codes($report));
        }
    }

    public function testKeepIsTheDefaultAndNothingHappens(): void
    {
        $board = $this->boardWithColumns('To do', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board, 'keep');
        $report = $this->sync($board);
        self::assertSame(['Doing', 'To do'], $this->columnNames($board));
        self::assertArrayNotHasKey('columns.unmatchedDeleted', $report->stats);
        self::assertStringNotContainsString('unmatched', $this->codes($report));
    }

    public function testASkippedStatusMapsToNothingAndABoardKeepsOneColumn(): void
    {
        $board = $this->boardWithColumns('Doing', 'Other');
        $this->statuses(['Doing']);
        $this->linked($board, 'delete', [['status' => 'Doing', 'column' => '__skip']]);
        $report = $this->sync($board);
        self::assertCount(1, $this->columnNames($board));
        self::assertStringContainsString('unmatched_column_kept_last', $this->codes($report));
    }

    public function testTheMapAndTheFallbackCount(): void
    {
        $board = $this->boardWithColumns('Mapped', 'Fallback', 'Spare');
        $ids   = [];
        foreach ($board->getColumns() as $column) {
            $ids[$column->getName()] = $column->getId();
        }
        $this->statuses(['Remote status']);
        $connection = $this->linked($board, 'delete', [['status' => 'Remote status', 'column' => $ids['Mapped']]]);
        $connection->setOptions(['unmatchedColumns' => 'delete', 'fallbackColumnId' => $ids['Fallback']]);
        $this->em->flush();
        $this->sync($board);
        self::assertSame(['Fallback', 'Mapped'], $this->columnNames($board));
    }

    public function testNothingHappensBeforeTheLastSliceAndAFailureKeepsTheChoiceForTheRetry(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $this->put($this->row('t1', 'One', 1, status: 'Doing'));
        $this->provider->failNext(new ProviderException(ProviderErrorCode::ServerError));

        $failed = $this->sync($board);
        self::assertSame(SyncStatus::Failed, $failed->status);
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board), 'a run that failed deletes nothing');
        self::assertFalse($this->connectionOf($board)?->isUnmatchedColumnsResolved());

        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, $this->codes($report));
        self::assertSame(['Doing'], $this->columnNames($board));
        self::assertTrue($this->connectionOf($board)?->isUnmatchedColumnsResolved());
    }

    public function testAcrossSlicesItWaitsForTheLastOne(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $tasks = [];
        for ($i = 1; $i <= 150; ++$i) {
            $tasks[] = $this->row('t' . $i, 'Task ' . $i, $i, status: 'Doing');
        }
        $this->put(...$tasks);

        $paused = $this->sync($board, maxPages: 1);
        self::assertSame(SyncStatus::Paused, $paused->status);
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertFalse($this->connectionOf($board)?->isUnmatchedColumnsResolved());

        $done = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $done->status, $this->codes($done));
        self::assertSame(['Doing'], $this->columnNames($board));
    }

    public function testADryRunDecidesNothing(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $this->sync($board, dryRun: true);
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertFalse($this->connectionOf($board)?->isUnmatchedColumnsResolved());
    }

    public function testAConnectionAlreadyImportedNeverDeletes(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $connection = $this->linked($board, 'keep');
        $this->sync($board);
        // A later change of the option cannot act on a board that has already imported.
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $connection->setOptions(['unmatchedColumns' => 'delete']);
        $this->em->flush();
        $report = $this->sync($board);
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertStringContainsString('unmatched_columns_incomplete', $this->codes($report));
    }

    public function testEditPermissionLostDuringTheRunKeepsEverything(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $this->put($this->row('t1', 'One', 1, status: 'Doing'));
        $lost = false;
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_STARTED, static function () use (&$lost): void {
            $lost = true;
        });
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$lost): void {
            if ($lost) {
                $event->markReadOnly();
            }
        });
        $report = $this->sync($board);
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertStringContainsString('unmatched_columns_denied', $this->codes($report));
    }

    public function testUnreadableStatusesKeepEverything(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->linked($board);
        $report = $this->sync($board);
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertStringContainsString('unmatched_columns_unknown', $this->codes($report));
    }

    public function testAProviderThatCannotListStatusesKeepsEverything(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $failing = new class extends InMemoryTaskProvider {
            public function listStatuses(ProviderCredential $credential, RemoteSource $source): array
            {
                throw new ProviderException(ProviderErrorCode::ServerError);
            }
        };
        $failing->putTask(self::LIST, $this->row('t1', 'One', 1, status: 'Doing'));
        $report = $this->sync($board, $failing);
        self::assertSame(SyncStatus::Succeeded, $report->status, $this->codes($report));
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertStringContainsString('unmatched_columns_unknown', $this->codes($report));
    }

    public function testAProviderWithoutStatusesKeepsEverything(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->linked($board);
        $plain  = new PlainTaskProvider(new InMemoryTaskProvider());
        $report = $this->sync($board, $plain);
        self::assertSame(SyncStatus::Succeeded, $report->status, $this->codes($report));
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertStringContainsString('unmatched_columns_unknown', $this->codes($report));
    }

    public function testADatabaseFailureLeavesTheColumnAndTheRunSucceeds(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The refusing trigger is written for SQLite.');
        }
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->statuses(['Doing']);
        $this->linked($board);
        $table = $this->em->getClassMetadata(BoardColumn::class)->getTableName();
        $connection->executeStatement('CREATE TRIGGER nowo_refuse BEFORE DELETE ON ' . $table . " BEGIN SELECT RAISE(ABORT, 'refused'); END");

        $report = $this->sync($board);
        $connection->executeStatement('DROP TRIGGER nowo_refuse');

        self::assertSame(SyncStatus::Succeeded, $report->status, $this->codes($report));
        self::assertSame(['Doing', 'Spare'], $this->columnNames($board));
        self::assertStringContainsString('unmatched_column_kept_failed', $this->codes($report));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testTheFormStoresTheChoiceAndIsNotDrawnByDefault(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'List', ContainerType::List, null, true, false));
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection', ['provider' => 'clickup', 'connection' => 'conn-1']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('select[name="task_board_sync_link[unmatchedColumns]"]'));
        // Posted by a host that draws its own dialog.
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/link', ['task_board_sync_link' => [
            'provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST, 'unmatchedColumns' => 'delete',
            '_token'   => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]]);
        self::assertSame('delete', $this->connectionOf($board)?->getUnmatchedColumns());
    }

    public function testAMissingChoiceIsKeepAndABadOneIsRefused(): void
    {
        $board = $this->boardWithColumns('Spare', 'Doing');
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'List', ContainerType::List, null, true, false));
        $this->login($this->owner);
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/link', ['task_board_sync_link' => [
            'provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST, 'unmatchedColumns' => 'evil',
            '_token'   => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]]);
        self::assertNull($this->connectionOf($board));
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/link', ['task_board_sync_link' => [
            'provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST,
            '_token'   => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]]);
        self::assertSame('keep', $this->connectionOf($board)?->getUnmatchedColumns());
    }
}
