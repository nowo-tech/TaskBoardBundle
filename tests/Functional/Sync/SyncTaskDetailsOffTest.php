<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusTime;

use function array_replace_recursive;

/**
 * Since 1.27.0 (TB-SYNC-3): `sync.import_archived: false` keeps the 1.26 rule (an archived provider task is not
 * created) and `sync.status_history.max_calls_per_run: 0` never asks for the time in status.
 */
final class SyncTaskDetailsOffTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['import_archived' => false, 'status_history' => ['max_calls_per_run' => 0]]]);
    }

    public function testArchivedTasksAndTheHistoryCanBeLeftOut(): void
    {
        $board    = $this->board();
        $provider = new FullTaskProvider();
        $provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false));
        $provider->history['a1'] = [new RemoteStatusTime('To do', new DateTimeImmutable('@1790000000'), 1)];
        $provider->putTask(self::LIST, $this->remote('a1', 'Active', 1));
        $provider->putTask(self::LIST, $this->remote('z1', 'Archived there', 2, archived: true));

        $report = $this->sync($board, $provider);
        self::assertSame([1, 1], [$report->created, $report->unchanged]);
        self::assertSame(0, $report->stat('statusHistory.calls'));
    }
}
