<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;

/**
 * Since 1.23 (audit EG-L2, delta): `sync.max_pages_per_run` bounds a run whose list size is unknown (an incremental
 * run), over all its slices, and the task counter carries over from slice to slice.
 */
final class SyncRunPageCapTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        $config                              = self::SYNC_CONFIG;
        $config['sync']['max_pages_per_run'] = 5;

        return $config;
    }

    public function testAnIncrementalRunStopsAtTheConfiguredCap(): void
    {
        $board = $this->board();
        $this->put($this->remote('c1', 'First', 1));
        self::assertSame(SyncStatus::Succeeded, $this->sync($board)->status);

        $runaway = new RepeatingProvider($this->provider, repeat: false, endless: 'new');
        $slices  = 0;
        do {
            $report = $this->sync($board, $runaway, maxPages: 2);
        } while ($report->status === SyncStatus::Paused && ++$slices < 30);

        self::assertSame(SyncStatus::Failed, $report->status);
        self::assertSame('response_too_large', $report->errors[0]->code);
        self::assertSame(5, $report->pagesDone);
        self::assertSame(2, $slices, 'two paused slices of 2 pages, then the fifth page ends the run');
        self::assertSame(5, $report->created, 'counters carry over the slices');
    }
}
