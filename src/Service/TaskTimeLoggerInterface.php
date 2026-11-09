<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;

/**
 * Since 2.10.0 (DDD audit G4): the port of {@see TaskTimeLogger}, which logs and removes manual time entries. A host's
 * application layer depends on it and can double or decorate it; the container aliases it to the bundle's class. The
 * bundle's own controllers keep using the class, so a host decorator wraps the host's calls only.
 */
interface TaskTimeLoggerInterface
{
    public function log(Task $task, object $user, int $minutes, DateTimeImmutable $date, ?string $note = null): TaskTimeEntry;

    /**
     * Removes a manual entry of the task; false when the task has none with that id. Since 1.23.2 the entry is deleted
     * explicitly (no orphan removal on the task any more: deleting a task keeps its time).
     */
    public function remove(Task $task, string $entryId, object $user): bool;
}
