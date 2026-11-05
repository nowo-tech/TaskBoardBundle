<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

use function array_map;
use function array_sum;
use function array_values;
use function json_encode;

/**
 * Since 1.25 (§5.8, §15.2, §15.3, §15.5): status types decide "done", the dialog's status → column map (column,
 * `__create`, `__skip`), folder / space sources with the list tag, and the per-column totals of the report.
 */
final class SyncStatusTest extends SyncPeopleFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        $config                        = parent::syncConfig();
        $config['sync']['list_as_tag'] = true;

        return $config;
    }

    public function testStatusTypesAndTheStatusMapDecideColumnsAndCompletion(): void
    {
        $board = $this->boardWithColumns('To do', 'Doing', 'Done');
        $doing = $this->column($board, 'Doing');
        $this->link($board, [
            ['status' => 'Shipped', 'column' => $doing->getId(), 'type' => 'closed'],
            ['status' => 'Ignored', 'column' => StatusColumns::SKIP],
            ['status' => 'In review', 'column' => StatusColumns::CREATE],
            ['status' => 'Doing', 'column' => StatusColumns::CREATE],
        ]);
        self::assertSame(['To do', 'Doing', 'Done', 'In review'], $this->columnNames($board), '__create makes the missing column only');

        $this->put(
            $this->row('a1', 'Shipped task', 1, 'Shipped', remoteStatus: new RemoteStatus('Shipped', RemoteStatusType::Closed)),
            $this->row('a2', 'Ignored task', 2, 'Ignored'),
            $this->row('a3', 'Review task', 3, 'In review', remoteStatus: new RemoteStatus('In review', RemoteStatusType::Custom)),
            $this->row('a4', 'Other task', 4, 'Other'),
        );
        $report = $this->sync($board);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(3, $report->created);
        self::assertSame(1, $report->stat('skipStatus'));
        self::assertContains('unmapped_status', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));
        self::assertSame(3, array_sum($report->perColumn));
        self::assertSame($report->perColumn, SyncReport::fromArray($report->toArray())->perColumn);

        $this->em->clear();
        $shipped = $this->taskTitled('Shipped task');
        self::assertSame(['Doing', true], [$shipped->getColumn()?->getName(), $shipped->isCompleted()]);
        $review = $this->taskTitled('Review task');
        self::assertSame(['In review', false], [$review->getColumn()?->getName(), $review->isCompleted()], 'a custom status in a non-last column is not done');
        $other = $this->taskTitled('Other task');
        self::assertSame(['Other', true], [$other->getColumn()?->getName(), $other->isCompleted()], 'no status type: the last column rule');
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Ignored task']));

        // Done → open marks the task incomplete; open → done completes it.
        $this->put(
            $this->row('a1', 'Shipped task', 5, 'To do', remoteStatus: new RemoteStatus('To do', RemoteStatusType::Open)),
            $this->row('a3', 'Review task', 6, 'Shipped', remoteStatus: new RemoteStatus('Shipped', RemoteStatusType::Done)),
        );
        $report = $this->sync($board);
        self::assertSame(2, $report->updated);
        $this->em->clear();
        $shipped = $this->taskTitled('Shipped task');
        self::assertSame(['To do', false], [$shipped->getColumn()?->getName(), $shipped->isCompleted()]);
        $review = $this->taskTitled('Review task');
        self::assertSame(['Doing', true], [$review->getColumn()?->getName(), $review->isCompleted()]);

        $run = $this->em->getRepository(TaskSyncRun::class)->findOneBy([], ['startedAt' => 'DESC']);
        self::assertInstanceOf(TaskSyncRun::class, $run);
        self::assertNull($run->getTimeMode());
    }

    public function testTheMapCanBeReplacedLater(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        self::assertNull($this->service()->updateStatusColumnMap($board, [['status' => 'Blocked', 'column' => StatusColumns::CREATE]]), 'not linked');
        $this->link($board, []);
        $connection = $this->service()->updateStatusColumnMap($board, [
            ['status' => 'Blocked', 'column' => StatusColumns::CREATE],
            ['status' => 'done', 'column' => StatusColumns::CREATE, 'type' => 'done'],
            ['status' => 'bad', 'column' => 'no-such-column'],
            'not an entry',
        ]);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        self::assertSame([
            ['status' => 'Blocked', 'column' => StatusColumns::CREATE],
            ['status' => 'done', 'column' => StatusColumns::CREATE, 'type' => 'done'],
        ], $connection->getStatusColumnMap());
        self::assertSame(['To do', 'Done', 'Blocked'], $this->columnNames($board));
        $connection->setStatusColumnMap([]);
        self::assertSame([], $connection->getStatusColumnMap());
    }

    public function testAFolderSourceReadsEveryListTagsTheListAndArchivesDeletions(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->provider->addSource(new RemoteSource(new RemoteLocation(RemoteLocationKind::Folder, '77'), 'Folder', null, ['Space 1']), [self::LIST, 'L2']);
        $this->provider->putTask(self::LIST, $this->row('f1', 'First', 1, listName: 'Backlog'));
        $this->provider->putTask('L2', $this->row('f2', 'Second', 2, listName: 'Ideas'));

        $report = $this->runOn($board, 'f:77');
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(2, $report->created);
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        self::assertSame(['folder', '77'], [$connection->getLocation()->kind->value, $connection->getLocation()->id]);
        $this->em->clear();
        self::assertSame(['list:Backlog'], $this->taskTitled('First')->getTags());
        self::assertSame(['list:Ideas'], $this->taskTitled('Second')->getTags());

        $this->provider->removeTask('L2', 'f2');
        $report = $this->runOn($board, 'f:77');
        self::assertSame(1, $report->archived);
        self::assertContains('existingSourceTaskIds:f:77', $this->provider->calls);

        $other  = $this->boardWithColumns('To do');
        $report = $this->runOn($other, 's:404');
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertSame('not_found', $report->errors[0]->code ?? null);
    }

    private function runOn(TaskBoard $board, string $containerId): SyncReport
    {
        $board = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        $report = $this->service()->run($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), $containerId, new SyncOptions(actor: $this->owner));
        $this->refreshOwner();

        return $report;
    }

    /**
     * @param list<array<string, string>> $map
     */
    private function link(TaskBoard $board, array $map): void
    {
        $this->service()->link($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, 's:1', true, false), $this->owner, [], $map);
    }

    private function column(TaskBoard $board, string $name): BoardColumn
    {
        foreach ($board->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }
        self::fail($name);
    }

    /**
     * @return list<string>
     */
    private function columnNames(TaskBoard $board): array
    {
        $board = $this->freshBoard($board);

        return array_values(array_map(static fn (BoardColumn $c): string => $c->getName(), $board->getColumns()->toArray()));
    }
}
