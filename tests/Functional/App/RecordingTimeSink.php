<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;

/**
 * Since 1.25: the host's tracked-time sink of the functional kernel (`sync.time_entries.sink: app.time_sink` when a
 * test sets it); records every call.
 */
final class RecordingTimeSink implements ImportedTimeEntrySinkInterface
{
    /** @var list<array{board: string, directory: string, window: TimeWindow, access: TimeAccess, entries: list<ImportedTimeEntryCandidate>, complete: bool}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function accept(TaskBoard $board, TaskSyncDirectory $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void
    {
        self::$calls[] = ['board' => $board->getId(), 'directory' => $directory->getId(), 'window' => $window, 'access' => $access, 'entries' => $entries, 'complete' => $windowComplete];
    }
}
