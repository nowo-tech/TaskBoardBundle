<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Time;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;

/**
 * Since 1.25: one tracked-time entry handed to the host's sink — the remote entry, its person in the directory (with
 * the mapping) and the local task when the remote task is linked on the run's board (null otherwise).
 */
final readonly class ImportedTimeEntryCandidate
{
    public function __construct(
        public RemoteTimeEntry $entry,
        public TaskSyncPerson $person,
        public ?Task $task,
    ) {
    }
}
