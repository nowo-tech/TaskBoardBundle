<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;

/**
 * Since 1.25 (`sync.time_entries.sink`): where the time phase hands tracked time. The bundle never writes TimeTrack
 * data: the host stages the entries and materialises them (e.g. TimeTrackBundle's ImportedTimeEntryWriter for mapped
 * people). Called synchronously under the board's sync lock, once per window and assignee chunk. Running timers and
 * entries without a task are filtered out before.
 */
interface ImportedTimeEntrySinkInterface
{
    /**
     * @param list<ImportedTimeEntryCandidate> $entries
     * @param bool $windowComplete every chunk of the window was read (the host may tombstone what it had for this
     *                             window and source and no longer received)
     */
    public function accept(TaskBoard $board, TaskSyncDirectory $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void;
}
