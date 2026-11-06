<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTask;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskDetails;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTaskPage;
use Nowo\TaskBoardBundle\Sync\SyncTaskDetailsWriter;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;

use function array_map;
use function array_reverse;
use function range;
use function sprintf;

/**
 * Since 1.27.0 (TB-SYNC-3): the rules around `remote_meta` — names of remote people stay in the people directory (the
 * stored JSON has references, forgetting a person is enough), the history never fails a run for a lasting error and
 * defers the task for a passing one, an empty active pass still reads the archived one, the report covers tasks read
 * but not written and stays bounded, and an archived import does not count against the host's limit of active tasks.
 */
final class SyncRemoteMetaRulesTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    private const NAME = 'Fake Person';

    public function testPeopleAreReferencesAndForgettingAPersonIsEnough(): void
    {
        $board = $this->board();
        $this->workspace([self::member('7')]);
        $this->put($this->remoteTask('t1', 1, new RemoteTaskDetails(customId: 'DEV-1', estimates: [['person' => new RemotePersonRef('7', self::NAME), 'minutes' => 90]]), [$this->peopleField()]));
        $this->sync($board);

        $task = $this->taskTitled('t1');
        self::assertSame('person:' . $this->personOf('7')->getId(), $this->itemOf('t1')->getRemoteMeta()['estimates'][0]['person']);
        $connection = $this->em->getConnection();
        self::assertStringNotContainsString(self::NAME, (string) $connection->fetchOne('SELECT remote_meta FROM task_board_sync_items'));
        self::assertStringNotContainsString(self::NAME, (string) $connection->fetchOne('SELECT field_value FROM task_board_sync_field_values'));
        self::assertSame('', $connection->fetchOne('SELECT value_text FROM task_board_sync_field_values'));

        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertStringContainsString(self::NAME . ': 90 min', $crawler->filter('[data-sync-detail="estimates"]')->text());
        self::assertStringContainsString(self::NAME, $crawler->filter('[data-field-type="people"]')->text());
        $crawler = $this->client->request('GET', self::BASE . 'board/' . $board->getId() . '/list');
        self::assertSame(self::NAME . ', 8 DEV-1', $crawler->filter('[data-title="t1"]')->attr('data-search-extra'), 'the list search finds the person');

        // The order of the JSON keys (MySQL reorders them) is not a change; a full re-sync writes nothing.
        $meta = $this->itemOf('t1')->getRemoteMeta();
        self::assertFalse($this->itemOf('t1')->setRemoteMeta(array_reverse($meta, true)));
        $again = $this->sync($board, full: true);
        self::assertSame([0, 0], [$again->stat('details.updated'), $again->stat('fields.values')]);

        // Forgotten: every view says "former user"; nothing else had the name.
        $directories = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $directories);
        $directories->anonymise($this->personOf('7'), $this->owner);
        $this->em->clear();
        $this->refreshOwner();
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertStringNotContainsString(self::NAME, (string) $this->client->getResponse()->getContent());

        // A person no longer there leaves a dash.
        $this->em->getConnection()->executeStatement('UPDATE task_board_sync_items SET remote_meta = ?', ['{"estimates":[{"person":"person:gone","minutes":5}]}']);
        $this->em->clear();
        $this->refreshOwner();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertStringContainsString('—: 5 min', $crawler->filter('[data-sync-detail="estimates"]')->text());
    }

    public function testWithoutADirectoryOnlyTheProviderIdIsKept(): void
    {
        $board = $this->board();
        $this->put($this->remoteTask('t1', 1, new RemoteTaskDetails(estimates: [['person' => new RemotePersonRef('7', self::NAME), 'minutes' => 30]]), [$this->peopleField()]));
        $this->sync($board, people: false);

        self::assertSame('ref:7', $this->itemOf('t1')->getRemoteMeta()['estimates'][0]['person']);
        self::assertSame(['ref:7', 'ref:8'], $this->em->getRepository(TaskSyncFieldValue::class)->findAll()[0]->getValue());
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $this->taskTitled('t1')->getId());
        self::assertStringContainsString('#7: 30 min', $crawler->filter('[data-sync-detail="estimates"]')->text());
        self::assertStringNotContainsString(self::NAME, (string) $this->client->getResponse()->getContent());
    }

    public function testAHistoryErrorNeverFailsARunAndAPassingOneDefersTheTask(): void
    {
        $board                   = $this->board();
        $provider                = $this->scripted();
        $provider->history['t1'] = [new RemoteStatusTime('To do', null, 3, true)];
        $provider->putTask(self::LIST, $this->remoteTask('t1', 1));
        self::assertSame(1, $this->sync($board, $provider)->created);

        // ClickUp answers 400 (invalid response) when the ClickApp is off: the last history stays.
        $provider->historyFailure = new ProviderException(ProviderErrorCode::InvalidResponse);
        $provider->putTask(self::LIST, $this->remoteTask('t1', 2));
        $report = $this->sync($board, $provider);
        self::assertSame(1, $report->stat(SyncTaskDetailsWriter::STAT_STATUS_FAILED));
        self::assertSame([], $report->errors);
        self::assertSame('To do', $this->itemOf('t1')->getRemoteMeta()['statusHistory'][0]['status']);

        // Rate limited: the run stops as usual and the task is read again next time.
        $provider->historyFailure = new ProviderException(ProviderErrorCode::RateLimited);
        $provider->putTask(self::LIST, $this->remoteTask('t1', 3));
        self::assertNotSame([], $this->sync($board, $provider)->errors);
        $this->em->clear();
        self::assertSame(0, $this->itemOf('t1')->getRemoteUpdatedAt()->getTimestamp(), 'deferred');
    }

    public function testAnEmptyActivePassStillReadsTheArchivedOneAndTheReportCoversTasksNotWritten(): void
    {
        $board                 = $this->board();
        $provider              = $this->scripted();
        $archived              = $this->remoteTask('z1', 1, archived: true);
        $provider->pages['']   = new RemoteTaskPage([], 'a0', false, true);
        $provider->pages['a0'] = new RemoteTaskPage([$archived], null, true);
        // The host's limit counts active tasks: an archived import is not refused.
        TestSyncLimitChecker::$refuseTasks = true;
        $report                            = $this->sync($board, $provider);
        TestSyncLimitChecker::$refuseTasks = false;
        self::assertSame([1, 1], [$report->created, $report->archived]);
        self::assertNotContains('paging_stopped', array_map(static fn (SyncError $warning): string => $warning->code, $report->warnings));
        self::assertTrue($this->taskTitled('z1')->isArchived());
        self::assertSame(Task::ARCHIVE_REASON_REMOTE, $this->taskTitled('z1')->getArchiveReason());
        self::assertSame(1, $report->stat('keys.id'));

        // Read again, nothing written: the report still lists the keys.
        $again = $this->sync($board, $provider);
        self::assertSame(0, $again->created + $again->updated);
        self::assertSame(1, $again->stat('keys.id'));
    }

    public function testTheReportIsBounded(): void
    {
        $board = $this->board();
        $keys  = array_map(static fn (int $n): string => sprintf('key_%d', $n), range(1, SyncTaskDetailsWriter::MAX_KEYS + 5));
        $this->put($this->remoteTask('t1', 1, null, [], $keys), $this->remoteTask('t2', 2, null, [], ['key_1', 'key_205']));
        $report = $this->sync($board);
        self::assertSame(SyncTaskDetailsWriter::MAX_KEYS, $report->stat('completeness.keys'));
        self::assertSame(2, $report->stat('keys.key_1'));
        self::assertSame(0, $report->stat('keys.key_205'));
    }

    private function scripted(): FullTaskProvider
    {
        $provider = new FullTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 1));
        $this->provider = $provider;

        return $provider;
    }

    private function peopleField(): RemoteCustomField
    {
        return new RemoteCustomField('f1', 'Owners', SyncFieldType::People, 'users', [], [], [['id' => '7', 'name' => self::NAME], 'junk', ['id' => '8']], self::NAME);
    }

    /**
     * @param list<RemoteCustomField> $fields
     * @param list<string> $keys
     */
    private function remoteTask(string $id, int $updated, ?RemoteTaskDetails $details = null, array $fields = [], array $keys = ['id'], bool $archived = false): RemoteTask
    {
        return new RemoteTask(
            task: new ImportedTaskDto(title: $id, externalId: $id, status: 'To do', priority: TaskPriority::Normal, hasPriority: false),
            remoteUpdatedAt: new DateTimeImmutable('@' . (1_790_000_000 + $updated)),
            archived: $archived,
            customFields: $fields,
            details: $details,
            presentKeys: $keys,
        );
    }
}
