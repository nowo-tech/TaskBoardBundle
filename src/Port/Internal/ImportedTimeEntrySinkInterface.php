<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;

/**
 * Since 1.25 (`sync.time_entries.sink`): where the time phase hands tracked time. The bundle never writes TimeTrack
 * data: the host stages the entries and materialises them (e.g. TimeTrackBundle's ImportedTimeEntryWriter for mapped
 * people). Called synchronously under the board's sync lock, once per window and assignee chunk. Running timers and
 * entries without a task are filtered out before.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see ImportedTimeEntryReceiverInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
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
