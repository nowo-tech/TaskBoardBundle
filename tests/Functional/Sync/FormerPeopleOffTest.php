<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateInterval;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingTimeSink;

use function array_map;
use function array_merge;

/**
 * Since 1.28.0: with nothing opted in, a plain sink behaves as before, the hours it could not take are said in the
 * report, the page has no button and the route answers 404.
 */
final class FormerPeopleOffTest extends SyncPeopleFunctionalTestCase
{
    public function testAPlainSinkStillGetsMappedPeopleOnlyAndTheReportSaysWhatIsPending(): void
    {
        RecordingTimeSink::reset();
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('2', 'Gone', 'gone@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'Task', 1));
        $now    = new DateTimeImmutable();
        $person = new RemotePersonRef('2', 'Gone', 'gone@remote.test');
        $start  = $now->sub(new DateInterval('P2D'));
        $this->provider->withTimeEntries([new RemoteTimeEntry('e1', 't1', $person, $start, $start->add(new DateInterval('PT90M')), 5400, false)]);

        $report = $this->sync($board, timeEntries: true, timeFrom: $now->sub(new DateInterval('P5D')));

        self::assertSame(1, $report->time->skippedUnmapped);
        self::assertSame(0, $report->time->entries);
        self::assertSame(5400, $report->stats['pending.unmappedSeconds'] ?? null);
        self::assertSame([], array_merge(...array_map(static fn (array $call): array => $call['entries'], RecordingTimeSink::$calls)));

        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection');
        self::assertResponseIsSuccessful();
        $row = $page->filter('[data-completeness-key="time_entries_unmapped"]');
        self::assertSame('pending_person', $row->attr('data-completeness-status'));
        self::assertStringContainsString('1.5 h', $row->text());

        $people = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection/people');
        self::assertCount(0, $people->filter('[data-testid="nowo-task-sync-people-former"]'));
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/people/former', ['task_board_sync_people_former' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people_former')]]);
        self::assertResponseStatusCodeSame(404);
    }
}
