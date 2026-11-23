<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\ImportedTimeEntryCandidate;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSyncDirectorySnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `ImportedTimeEntrySinkInterface` (the 2.14 interface, removed in 3.0): where the time
 * phase hands tracked time, with the board and the people directory as snapshots. Read each candidate through its id
 * accessors ({@see ImportedTimeEntryCandidate::personId()}, `taskId()`, `user()`, ...). Implement
 * {@see OwnerlessTimeEntryReceiverInterface} to receive the hours of people with no host user too.
 *
 * Set `sync.time_entries.sink` to a service implementing it.
 */
interface ImportedTimeEntryReceiverInterface
{
    /**
     * @param list<ImportedTimeEntryCandidate> $entries
     * @param bool $windowComplete every chunk of the window was read
     */
    public function accept(TaskBoardSnapshot $board, TaskSyncDirectorySnapshot $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void;
}
