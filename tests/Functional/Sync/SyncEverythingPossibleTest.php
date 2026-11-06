<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\Status\FirstImportFinisher;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

use function array_map;
use function array_values;
use function json_encode;
use function usort;

/**
 * Since 1.28.0 (TB-SYNC-3 addendum): task types, locations, tag colours, nested subtasks, status colours and order,
 * the source's description, and what the API does not give in the report.
 */
final class SyncEverythingPossibleTest extends SyncPeopleFunctionalTestCase
{
    private function detailed(string $id, string $title, RemoteTaskDetails $details, int $updated = 1): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $title, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            details: $details,
        );
    }

    public function testTaskTypeLocationsAndColoursReachThePanelAndARepeatChangesNothing(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->provider->withTaskTypes(['1007' => 'Bug']);
        $this->put(
            $this->detailed('t1', 'Typed', new RemoteTaskDetails(taskTypeId: '1007', workspaceId: '900', locations: [['id' => 'L2', 'name' => 'Other list']], tagColours: ['urgent' => ['fg' => '#FFF', 'bg' => '#123456']])),
            $this->detailed('t2', 'Default', new RemoteTaskDetails(taskTypeId: '0', workspaceId: '900'), 2),
            $this->detailed('t3', 'Unknown', new RemoteTaskDetails(taskTypeId: '55', workspaceId: '900'), 3),
            $this->detailed('t4', 'None', new RemoteTaskDetails(), 4),
        );
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status);

        self::assertSame('Bug', $this->itemOf('t1')->getRemoteMeta()['taskType'] ?? null);
        self::assertSame('Task', $this->itemOf('t2')->getRemoteMeta()['taskType'] ?? null);
        self::assertSame('#55', $this->itemOf('t3')->getRemoteMeta()['taskType'] ?? null);
        self::assertArrayNotHasKey('taskType', $this->itemOf('t4')->getRemoteMeta() ?? []);

        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/task/' . $this->taskTitled('Typed')->getId());
        self::assertSame('Bug', $page->filter('[data-sync-detail="task-type"] dd')->text());
        self::assertSame('Other list', $page->filter('[data-sync-detail="locations"] dd')->text());
        $badge = $page->filter('[data-sync-detail="tag-colours"] .badge');
        self::assertSame('urgent', $badge->text());
        self::assertSame('background-color: #123456;color: #FFF;', $badge->attr('style'));

        $again = $this->sync($board, full: true);
        self::assertSame(0, $again->stats['details.updated'] ?? 0, 'idempotent');
    }

    public function testAStoredColourThatIsNotHexNeverReachesTheStyle(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->put($this->detailed('t1', 'Typed', new RemoteTaskDetails(tagColours: ['bad' => ['fg' => 'red;x:y', 'bg' => '#123']])));
        $this->sync($board);
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/task/' . $this->taskTitled('Typed')->getId());
        self::assertSame('background-color: #123;', $page->filter('[data-sync-detail="tag-colours"] .badge')->attr('style'));
    }

    public function testRefusedTypesKeepTheLastNameAndTheReportSaysSo(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->provider->withTaskTypes(['1007' => 'Bug']);
        $this->put($this->detailed('t1', 'Typed', new RemoteTaskDetails(taskTypeId: '1007', workspaceId: '900')));
        $this->sync($board);

        $refusing = new class extends InMemoryTaskProvider {
            public function fetchTaskTypes(ProviderCredential $credential, string $workspaceId): array
            {
                throw new ProviderException(ProviderErrorCode::Forbidden);
            }
        };
        $refusing->putTask(self::LIST, $this->detailed('t1', 'Typed', new RemoteTaskDetails(taskTypeId: '1007', workspaceId: '900'), 5));
        $report = $this->sync($board, $refusing, full: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame('Bug', $this->itemOf('t1')->getRemoteMeta()['taskType'] ?? null);
        self::assertSame(1, $report->stats['taskTypes.unavailable'] ?? null);

        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        self::assertSame('unavailable', $page->filter('[data-completeness-key="task_types"]')->attr('data-completeness-status'));
    }

    public function testARateLimitOnTypesPausesTheRun(): void
    {
        $board   = $this->boardWithColumns('To do');
        $limited = new class extends InMemoryTaskProvider {
            public function fetchTaskTypes(ProviderCredential $credential, string $workspaceId): array
            {
                throw new RateLimitedException(30);
            }
        };
        $limited->putTask(self::LIST, $this->detailed('t1', 'Typed', new RemoteTaskDetails(taskTypeId: '1007', workspaceId: '900')));
        $report = $this->sync($board, $limited);
        self::assertSame(SyncStatus::RateLimited, $report->status, (string) json_encode($report->toArray()));
    }

    public function testSubtasksThreeLevelsDeepInAnyOrderClosedOnesToo(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->put(
            $this->row('leaf', 'Leaf', 1, status: 'Done', parent: 'mid'),
            $this->row('mid', 'Mid', 2, parent: 'top'),
            $this->row('top', 'Top', 3),
        );
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        $leaf = $this->taskTitled('Leaf');
        self::assertSame('Mid', $leaf->getParent()?->getTitle());
        self::assertSame('Top', $leaf->getParent()?->getParent()?->getTitle());
        self::assertSame('Done', $leaf->getColumn()?->getName());
    }

    public function testCreatedColumnsTakeTheStatusColourAndOrderAndOthersStayPut(): void
    {
        $board = $this->boardWithColumns('Mine');
        $this->provider->withStatuses(self::LIST, [
            new RemoteStatus('Open', null, '#111111', 0),
            new RemoteStatus('Review', null, '#222222', 1),
            new RemoteStatus('Closed', null, '#333333', 2),
        ]);
        // Tasks arrive in another order than the statuses.
        $this->put(
            $this->row('t1', 'A', 1, status: 'Closed', remoteStatus: new RemoteStatus('Closed', null, '#333333', 2)),
            $this->row('t2', 'B', 2, status: 'Open'),
            $this->row('t3', 'C', 3, status: 'Review', remoteStatus: new RemoteStatus('Review', null, '#222222', 1)),
        );
        $this->sync($board);
        self::assertSame(['Mine', 'Open', 'Review', 'Closed'], $this->columnsInOrder($board));
        self::assertSame('#222222', $this->column($board, 'Review')->getColor());
        self::assertSame('#111111', $this->column($board, 'Open')->getColor(), 'a row without the colour takes the status list\'s');
        self::assertNull($this->column($board, 'Mine')->getColor());
        self::assertSame([], $this->connectionOf($board)?->createdColumns());

        // Changed by hand: later syncs leave it.
        $this->column($board, 'Review')->setColor('#abcdef');
        $this->em->flush();
        $this->sync($board, full: true);
        self::assertSame('#abcdef', $this->column($board, 'Review')->getColor());
    }

    public function testColumnsCreatedAtLinkFollowTooAndTheDescriptionIsKept(): void
    {
        $board = $this->boardWithColumns('Mine');
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'List', ContainerType::List, null, true, false, description: 'About the list'));
        $this->provider->withStatuses(self::LIST, [new RemoteStatus('First', null, '#111111', 0), new RemoteStatus('Second', null, null, 1)]);
        $this->service()->link($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new RemoteContainer(self::LIST, 'List', ContainerType::List, null, true, false), $this->owner, [], [
            ['status' => 'Second', 'column' => '__create'],
            ['status' => 'First', 'column' => '__create'],
        ]);
        $this->sync($board);
        self::assertSame(['Mine', 'First', 'Second'], $this->columnsInOrder($board));
        self::assertSame('#111111', $this->column($board, 'First')->getColor());
        self::assertSame('About the list', $this->connectionOf($board)?->getSourceDescription());

        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        self::assertStringContainsString('About the list', $page->filter('[data-testid="nowo-task-sync-source-description"]')->text());
    }

    public function testAFailureReadingTheSourceDoesNotFailTheImportAndDryRunsWriteNothing(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->sync($board, dryRun: true);
        self::assertNull($this->connectionOf($board)?->getSourceDescription());

        $failing = new class extends InMemoryTaskProvider {
            public function findSource(ProviderCredential $credential, string $containerId): ?RemoteSource
            {
                throw new ProviderException(ProviderErrorCode::ServerError);
            }
        };
        $failing->putTask(self::LIST, $this->row('t1', 'One', 1));
        $report = $this->sync($board, $failing);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertContains('source_details_unavailable', array_map(static fn (SyncError $warning): string => $warning->code, $report->warnings));
        self::assertInstanceOf(FirstImportFinisher::class, self::getContainer()->get(FirstImportFinisher::class));
    }

    public function testTheReportSaysWhatTheApiDoesNotGive(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->provider->withApiGaps(['reminders' => 'not_exposed']);
        $this->put($this->row('t1', 'One', 1));
        $this->sync($board);
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        self::assertSame('not_exposed', $page->filter('[data-completeness-key="reminders"]')->attr('data-completeness-status'));
    }

    /**
     * @return list<string>
     */
    private function columnsInOrder(TaskBoard $board): array
    {
        $this->em->clear();
        $this->refreshOwner();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $columns = array_values($fresh->getColumns()->toArray());
        usort($columns, static fn (BoardColumn $a, BoardColumn $b): int => $a->getPosition() <=> $b->getPosition());

        return array_map(static fn (BoardColumn $column): string => $column->getName(), $columns);
    }

    private function column(TaskBoard $board, string $name): BoardColumn
    {
        foreach ($this->em->getRepository(BoardColumn::class)->findBy(['board' => $board->getId()]) as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }
        self::fail('No column ' . $name);
    }
}
