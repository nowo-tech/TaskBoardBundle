<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateInterval;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncPhase;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPersonMapping;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Sync\Time\TimeEntryPhase;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingTimeSink;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function json_encode;
use function range;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;

/**
 * Since 1.25 (§2.5, §3 E7/E8, §5.1, §13.1 Sink): the `time_entries` phase hands tracked time to the host's sink,
 * window by window and chunk by chunk, resumable; the bundle never writes time itself.
 */
final class SyncTimeEntriesTest extends SyncPeopleFunctionalTestCase
{
    use MapsTimePeople;

    public function testAdminTimeIsReadPerWindowAndAssigneeChunk(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001'), self::member('9002'), self::member('9003')]);
        $this->put($this->row('t1', 'Task', 1));
        $now = new DateTimeImmutable();
        $this->provider->withTimeEntries([
            $this->entry('e1', '9001', 't1', $now->sub(new DateInterval('P40D'))),
            $this->entry('e2', '9001', 't1', $now->sub(new DateInterval('P39D')), running: true),
            $this->entry('e3', '9002', null, $now->sub(new DateInterval('P38D'))),
            $this->entry('e4', '9003', 'zz', $now->sub(new DateInterval('P5D'))),
            $this->entry('e5', '9555', 't1', $now->sub(new DateInterval('P4D'))),
        ]);

        $report = $this->sync($board, timeEntries: true, timeFrom: $now->sub(new DateInterval('P45D')));
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame('admin', $report->time->mode);
        self::assertSame([2, 2, 1, 1], [$report->time->entries, $report->time->windows, $report->time->skippedRunning, $report->time->skippedTaskless]);
        self::assertSame([false, true, false, true], array_map(static fn (array $c): bool => $c['complete'], RecordingTimeSink::$calls), 'complete on the last chunk of each window');
        self::assertSame(['admin'], array_values(array_unique(array_map(static fn (array $c): string => $c['access']->mode->value, RecordingTimeSink::$calls))));

        $delivered = array_merge(...array_map(static fn (array $c): array => $c['entries'], RecordingTimeSink::$calls));
        self::assertSame(['e1', 'e4'], array_map(static fn (ImportedTimeEntryCandidate $c): string => $c->entry->id, $delivered));
        self::assertSame('Task', $delivered[0]->task?->getTitle());
        self::assertSame('9001', $delivered[0]->person->getProviderUserId());
        // Since 1.27.0: the host user of the entry (the person's mapping); none once the person is unmapped.
        self::assertSame($delivered[0]->person->getMappedUser(), $delivered[0]->user());
        self::assertNotNull($delivered[0]->user());
        $delivered[0]->person->map(TaskSyncPersonMapping::Unmatched, null, null, new DateTimeImmutable());
        self::assertNull($delivered[0]->user());
        self::assertNull($delivered[1]->task, 'not linked on this board');
        // e5: 9555 is not in the directory, so its id is never sent as an assignee.
        self::assertSame(self::LIST, RecordingTimeSink::$calls[0]['window']->location->id);

        $run = $this->em->getRepository(TaskSyncRun::class)->findOneBy([]);
        self::assertInstanceOf(TaskSyncRun::class, $run);
        self::assertSame('admin', $run->getTimeMode());
    }

    public function testAMemberTokenReadsItsOwnTimeOnly(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        // The token user is not listed as a member: the time entries bring them into the directory.
        $this->provider->withWorkspace(self::WORKSPACE, [self::member('9002')], new TimeAccess(TimeAccessMode::Member, '9001'));
        $now = new DateTimeImmutable();
        $this->provider->withTimeEntries([$this->entry('e1', '9001', 't1', $now->sub(new DateInterval('P2D'))), $this->entry('e2', '9002', 't1', $now->sub(new DateInterval('P2D')))]);

        $report = $this->sync($board, timeEntries: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame('member', $report->time->mode);
        self::assertContains('time_member_mode', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));
        self::assertSame(1, $report->people->created - 1, 'the member, then the token user from its entries');
        self::assertCount(1, RecordingTimeSink::$calls, 'an empty board reads one 30-day window');
        self::assertTrue(RecordingTimeSink::$calls[0]['complete']);
        self::assertSame(['e1'], array_map(static fn (ImportedTimeEntryCandidate $c): string => $c->entry->id, RecordingTimeSink::$calls[0]['entries']));
    }

    public function testAWindowAtTheRowCapIsHalvedDownToOneHour(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001')]);
        $now   = new DateTimeImmutable();
        $start = $now->sub(new DateInterval('P20D'));
        $this->provider->withTimeEntries(array_map(fn (int $i): RemoteTimeEntry => $this->entry('e' . $i, '9001', 't1', $start->add(new DateInterval('PT' . ($i * 7200) . 'S'))), range(1, 12)));

        $report = $this->sync($board, timeEntries: true, timeFrom: $start);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame(12, $report->time->entries);
        self::assertGreaterThan(0, $report->time->windowsSplit);

        // Ten entries inside one hour cannot be split further: the run fails, nothing half-read is delivered as complete.
        $other = $this->boardWithColumns('To do');
        $this->provider->withTimeEntries(array_map(fn (int $i): RemoteTimeEntry => $this->entry('x' . $i, '9001', 't1', $start->add(new DateInterval('PT' . $i . 'S'))), range(1, 10)));
        RecordingTimeSink::reset();
        $report = $this->sync($other, timeEntries: true, timeFrom: $start);
        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertSame('response_too_large', $report->errors[0]->code ?? null);
        self::assertSame([], RecordingTimeSink::$calls);
    }

    public function testTheWindowCapPausesAndTheNextSliceResumesFromTheCursor(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001')]);
        $this->put($this->row('t1', 'Old task', 1, createdAt: new DateTimeImmutable('-200 days')));
        $this->provider->withTimeEntries([]);

        $report = $this->sync($board, timeEntries: true);
        self::assertSame(SyncStatus::Paused, $report->status, (string) json_encode($report->toArray()));
        self::assertCount(5, RecordingTimeSink::$calls);
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $cursor = $connection->getCursor();
        self::assertInstanceOf(SyncCursor::class, $cursor);
        self::assertSame(SyncPhase::TimeEntries, $cursor->phase);
        self::assertSame('admin', $cursor->timeMode);
        self::assertSame(RecordingTimeSink::$calls[4]['window']->to->getTimestamp(), $cursor->timeWindowStart?->getTimestamp());
        self::assertEqualsWithDelta((new DateTimeImmutable('-200 days'))->getTimestamp(), RecordingTimeSink::$calls[0]['window']->from->getTimestamp(), 5, 'the first window starts at the oldest task');
        self::assertEquals($cursor, SyncCursor::fromArray($cursor->toArray()));

        $report = $this->sync($board, timeEntries: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame(RecordingTimeSink::$calls[5]['window']->from->getTimestamp(), $cursor->timeWindowStart?->getTimestamp());
        self::assertSame(count(RecordingTimeSink::$calls), $report->time->windows);
    }

    public function testATimeBudgetPausesAfterAWindowAndARateLimitResumesTheSameChunk(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001'), self::member('9002'), self::member('9003')]);
        $this->provider->withTimeEntries([]);
        $now    = new DateTimeImmutable();
        $report = $this->sync($board, timeEntries: true, timeFrom: $now->sub(new DateInterval('P50D')), timeBudgetSeconds: 0);
        self::assertSame(SyncStatus::Paused, $report->status, (string) json_encode($report->toArray()));
        self::assertCount(2, RecordingTimeSink::$calls);

        $limited = new class extends InMemoryTaskProvider {
            public int $timeCalls = 0;

            public function fetchTimeEntries(ProviderCredential $credential, TimeEntryQuery $query): iterable
            {
                if (++$this->timeCalls === 2) {
                    throw new RateLimitedException(30);
                }

                return parent::fetchTimeEntries($credential, $query);
            }
        };
        $limited->withWorkspace(self::WORKSPACE, [self::member('9001'), self::member('9002'), self::member('9003')])->withTimeEntries([]);
        $other = $this->boardWithColumns('To do');
        RecordingTimeSink::reset();
        $report = $this->sync($other, $limited, timeEntries: true, timeFrom: $now->sub(new DateInterval('P10D')));
        self::assertSame(SyncStatus::RateLimited, $report->status);
        $cursor = $this->connectionOf($other)?->getCursor();
        self::assertSame(1, $cursor?->timeChunk);
        $report = $this->sync($other, $limited, timeEntries: true);
        self::assertSame(SyncStatus::Succeeded, $report->status);
        self::assertSame([false, true], array_map(static fn (array $c): bool => $c['complete'], RecordingTimeSink::$calls));
    }

    public function testOnlyPeopleMappedToAUserReachTheSink(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001'), self::member('9007', 'nobody@example.test')]);
        $now = new DateTimeImmutable();
        $this->provider->withTimeEntries([$this->entry('e1', '9001', 't1', $now->sub(new DateInterval('P2D'))), $this->entry('e7', '9007', 't1', $now->sub(new DateInterval('P2D')))]);

        $report = $this->sync($board, timeEntries: true);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame([1, 1], [$report->time->entries, $report->time->skippedUnmapped]);
        $delivered = array_merge(...array_map(static fn (array $c): array => $c['entries'], RecordingTimeSink::$calls));
        self::assertSame(['e1'], array_map(static fn (ImportedTimeEntryCandidate $c): string => $c->entry->id, $delivered));
    }

    public function testATokenUserFirstSeenInTheTimePhaseAndUnmappedGetsNothingDelivered(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->provider->withWorkspace(self::WORKSPACE, [self::member('9001')], new TimeAccess(TimeAccessMode::Member, '9008'));
        $this->provider->withTimeEntries([$this->entry('e8', '9008', 't1', (new DateTimeImmutable())->sub(new DateInterval('P2D')))]);

        $report = $this->sync($board, timeEntries: true);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame([0, 1], [$report->time->entries, $report->time->skippedUnmapped]);
        self::assertContains('people_unmatched_new', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));
        self::assertSame([], array_merge(...array_map(static fn (array $c): array => $c['entries'], RecordingTimeSink::$calls)));
    }

    public function testTheMemberFallbackReadsPerTaskIntervalsWhenAsked(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->provider->withWorkspace(self::WORKSPACE, [self::member('9001')], new TimeAccess(TimeAccessMode::Member, '9001'));
        $this->provider->withTimeEntries([]);
        $this->put($this->row('t1', 'One', 1), $this->row('t2', 'Two', 2), $this->row('t3', 'Three', 3));
        foreach (['t1', 't2', 't3'] as $task) {
            $this->provider->withTaskIntervals($task, [$this->entry('i' . $task, '9001', $task, new DateTimeImmutable('-3 days'))]);
        }

        $report = $this->sync($board, timeEntries: true, timeFallbackPerTask: true);
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame('fallback', $report->time->mode);
        $codes = array_map(static fn (SyncError $w): string => $w->code, $report->warnings);
        self::assertContains('time_fallback_partial', $codes);
        self::assertContains('time_member_mode', $codes);
        self::assertCount(2, RecordingTimeSink::$calls, 'capped at fallback_max_tasks_per_run');
        self::assertSame(2, $report->time->entries);
        self::assertSame(2, $report->time->fallbackTasks);
        self::assertSame([], array_filter($report->stats, static fn (string $name): bool => str_starts_with($name, 'time.') || str_starts_with($name, 'people.'), ARRAY_FILTER_USE_KEY), 'typed counters are not repeated in stats');

        foreach (['t1', 't2', 't3'] as $task) {
            $this->provider->withTaskIntervals($task, null);
        }
        RecordingTimeSink::reset();
        $report = $this->sync($board, timeEntries: true, timeFallbackPerTask: true);
        self::assertContains('time_fallback_unavailable', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));
        self::assertSame([], RecordingTimeSink::$calls);
    }

    public function testThePhaseNeedsTheOptionTheCapabilityAndADirectory(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001')]);
        $this->provider->withTimeEntries([$this->entry('e1', '9001', 't1', new DateTimeImmutable('-1 day'))]);
        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status);
        self::assertSame(SyncStatus::Succeeded, $this->sync($board, timeEntries: true, people: false)->status);
        self::assertSame([], RecordingTimeSink::$calls);
        self::assertSame(SyncStatus::Succeeded, $this->sync($board, timeEntries: true, dryRun: true)->status);
        self::assertSame([], RecordingTimeSink::$calls);
    }

    public function testThePhaseItselfDoesNothingWithoutASinkOrADirectory(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->sync($board);
        $board      = $this->freshBoard($board);
        $connection = $this->connectionOf($board);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        $ctx    = new SyncRunContext('run', $board, $connection, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new SyncOptions(actor: $this->owner, timeEntries: true), $this->owner, SyncMode::Full, null, new DateTimeImmutable(), 'clickup', 1);
        $em     = $this->em;
        $store  = self::getContainer()->get(SyncStore::class);
        $people = self::getContainer()->get(TaskSyncPersonRepository::class);
        $dirs   = self::getContainer()->get(TaskSyncPeopleDirectoryService::class);
        self::assertInstanceOf(SyncStore::class, $store);
        self::assertInstanceOf(TaskSyncPersonRepository::class, $people);
        self::assertInstanceOf(TaskSyncPeopleDirectoryService::class, $dirs);
        $phase = new TimeEntryPhase($em, $store, $dirs, $people);
        self::assertFalse($phase->supports($ctx));
        self::assertTrue($phase->run($ctx, null), 'nothing to do');
        $ctx->directoryId = 'missing';
        self::assertTrue((new TimeEntryPhase($em, $store, $dirs, $people, new RecordingTimeSink()))->run($ctx, null));
        self::assertSame([], RecordingTimeSink::$calls);
    }

    private function entry(string $id, string $person, ?string $task, DateTimeImmutable $start, bool $running = false): RemoteTimeEntry
    {
        return new RemoteTimeEntry($id, $task, self::ref($person), $start, $running ? null : $start->add(new DateInterval('PT1H')), $running ? -1 : 3600, $running);
    }
}
