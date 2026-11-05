<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;

/**
 * Persistence port for TaskTimeEntry entities.
 */
interface TaskTimeEntryRepositoryInterface
{
    /**
     * Since 1.23.2: schedules the deletion of one entry (a person removed it from the task); the next flush deletes it.
     * Deleting a task never deletes its entries any more (they are detached), so this is the only delete path.
     */
    public function remove(TaskTimeEntry $entry): void;
}
