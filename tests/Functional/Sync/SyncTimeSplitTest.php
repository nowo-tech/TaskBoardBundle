<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateInterval;
use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingTimeSink;

use function array_map;
use function array_merge;
use function array_replace_recursive;
use function array_unique;
use function count;
use function json_encode;
use function range;
use function sort;

/**
 * Since 1.25 (audit FP-L4): a dense window is split under a per-slice call budget, every complete split is delivered
 * at once, and the cursor keeps the split position: the call budget and a rate limit resume from the last delivered
 * split, never from the start of the window, and no entry is delivered twice.
 */
final class SyncTimeSplitTest extends SyncPeopleFunctionalTestCase
{
    use MapsTimePeople;

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['time_entries' => ['max_calls_per_run' => 3]]]);
    }

    public function testTheCallBudgetPausesInsideADenseWindowAndResumesFromTheSplit(): void
    {
        $board = $this->boardWithColumns('To do', 'Done');
        $this->workspace([self::member('9001')]);
        $start = (new DateTimeImmutable())->sub(new DateInterval('P20D'));
        $this->provider->withTimeEntries($this->dense($start));

        $report = $this->sync($board, timeEntries: true, timeFrom: $start);
        self::assertSame(SyncStatus::Paused, $report->status, (string) json_encode($report->toArray()));
        self::assertContains('time_paused', array_map(static fn (SyncError $w): string => $w->code, $report->warnings));
        $cursor = $this->connectionOf($board)?->getCursor();
        self::assertSame($start->getTimestamp(), $cursor?->timeSplitStart?->getTimestamp(), 'the split position is in the cursor');
        self::assertGreaterThan(0, $cursor->timeSplitSeconds);
        self::assertSame(3, $report->time->windowsSplit, 'three calls, all split');

        for ($slice = 0; $slice < 20 && $report->status === SyncStatus::Paused; ++$slice) {
            $report = $this->sync($board, timeEntries: true);
        }
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame($this->ids(), $this->expected(), 'every entry once, none twice');
        self::assertNull($this->connectionOf($board)?->getCursor());
    }

    public function testARateLimitInsideASplitWindowResumesFromTheLastDeliveredSplit(): void
    {
        $board   = $this->boardWithColumns('To do', 'Done');
        $start   = (new DateTimeImmutable())->sub(new DateInterval('P20D'));
        $limited = new class extends InMemoryTaskProvider {
            /** @var list<int> query starts */
            public array $froms = [];

            public bool $limit = true;

            public function fetchTimeEntries(ProviderCredential $credential, TimeEntryQuery $query): iterable
            {
                if ($this->limit && count($this->froms) === 2) {
                    $this->limit = false;

                    throw new RateLimitedException(30);
                }
                $this->froms[] = $query->from->getTimestamp();

                return parent::fetchTimeEntries($credential, $query);
            }
        };
        $limited->withWorkspace(self::WORKSPACE, [self::member('9001')])->withTimeEntries($this->dense($start));

        $report = $this->sync($board, $limited, timeEntries: true, timeFrom: $start);
        self::assertSame(SyncStatus::RateLimited, $report->status, (string) json_encode($report->toArray()));
        $cursor = $this->connectionOf($board)?->getCursor();
        self::assertSame($start->getTimestamp(), $cursor?->timeSplitStart?->getTimestamp());
        $split = $cursor->timeSplitSeconds;
        self::assertGreaterThan(0, $split);

        $limited->froms = [];
        for ($slice = 0; $slice < 20 && $report->status !== SyncStatus::Succeeded; ++$slice) {
            $report = $this->sync($board, $limited, timeEntries: true);
        }
        self::assertSame(SyncStatus::Succeeded, $report->status, (string) json_encode($report->toArray()));
        self::assertSame($start->getTimestamp(), $limited->froms[0], 'the resumed read starts at the split, with the split length');
        self::assertSame($this->ids(), $this->expected());
    }

    /**
     * Twelve entries two hours apart: the first 24 hours of the window are above the row cap (10).
     *
     * @return list<RemoteTimeEntry>
     */
    private function dense(DateTimeImmutable $start): array
    {
        return array_map(static fn (int $i): RemoteTimeEntry => new RemoteTimeEntry('e' . $i, 't1', self::ref('9001'), $start->add(new DateInterval('PT' . ($i * 7200) . 'S')), $start->add(new DateInterval('PT' . ($i * 7200 + 3600) . 'S')), 3600, false), range(1, 12));
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        $ids = array_merge(...array_map(static fn (array $call): array => array_map(static fn (ImportedTimeEntryCandidate $c): string => $c->entry->id, $call['entries']), RecordingTimeSink::$calls));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function expected(): array
    {
        $ids = array_unique(array_map(static fn (int $i): string => 'e' . $i, range(1, 12)));
        sort($ids);

        return $ids;
    }
}
