<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\Sync\Dto\PeopleCounts;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\TimeCounts;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.25: the typed people / time counters of a run report (contract §2.6), split from the run's counters and
 * carried through the stored report.
 */
final class SyncCountsTest extends TestCase
{
    public function testPeopleCountersAreReadFromTheRunCountersAndBack(): void
    {
        $counters = ['people.seen' => 4, 'people.created' => 2, 'people.autoMatched' => 1, 'people.changedEmail' => 1, 'time.entries' => 9, 'skipStatus' => 3];
        $people   = PeopleCounts::fromCounters($counters);

        self::assertSame([4, 2, 1, 0, 0, 1, 0], [$people->seen, $people->created, $people->autoMatched, $people->suggested, $people->unmatchedNew, $people->changedEmail, $people->autoUnmatched]);
        self::assertSame(['people.seen' => 4, 'people.created' => 2, 'people.autoMatched' => 1, 'people.changedEmail' => 1], $people->toCounters());
        self::assertEquals($people, PeopleCounts::fromArray($people->toArray()));
        self::assertEquals(new PeopleCounts(), PeopleCounts::fromArray([]));
    }

    public function testTimeCountersCarryTheModeAndAreReadFromTheRunCounters(): void
    {
        $time = TimeCounts::fromCounters('admin', ['time.windows' => 2, 'time.windowsSplit' => 1, 'time.entries' => 7, 'time.skippedRunning' => 1, 'time.skippedTaskless' => 1, 'time.fallbackTasks' => 0, 'people.seen' => 3]);

        self::assertSame(['mode' => 'admin', 'windows' => 2, 'windowsSplit' => 1, 'entries' => 7, 'skippedRunning' => 1, 'skippedTaskless' => 1, 'fallbackTasks' => 0, 'skippedUnmapped' => 0], $time->toArray());
        self::assertSame(['time.windows' => 2, 'time.windowsSplit' => 1, 'time.entries' => 7, 'time.skippedRunning' => 1, 'time.skippedTaskless' => 1], $time->toCounters());
        self::assertEquals($time, TimeCounts::fromArray($time->toArray()));
        self::assertNull(TimeCounts::fromArray(['mode' => 5])->mode);
        self::assertNull(TimeCounts::fromCounters(null, [])->mode);
    }

    public function testTheReportStoresTheTypedCountersOutsideStats(): void
    {
        $report = new SyncReport(
            runId: 'r',
            boardId: 'b',
            provider: 'clickup',
            mode: SyncMode::Initial,
            status: SyncStatus::Succeeded,
            stats: ['checklist.created' => 2],
            people: new PeopleCounts(seen: 3, created: 1),
            time: new TimeCounts('member', entries: 4, fallbackTasks: 1),
        );
        $array = $report->toArray();

        self::assertSame(['checklist.created' => 2], $array['stats']);
        self::assertSame(3, $array['people']['seen']);
        self::assertSame('member', $array['time']['mode']);
        $back = SyncReport::fromArray($array);
        self::assertEquals($report->people, $back->people);
        self::assertEquals($report->time, $back->time);
        self::assertSame(2, $back->stat('checklist.created'));
        self::assertEquals(new PeopleCounts(), SyncReport::fromArray([])->people);
    }
}
