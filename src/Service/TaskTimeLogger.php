<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskTimeEntryRepositoryInterface;

use function trim;

/**
 * Since 1.15: manual time entries (minutes, date, note) logged from the task detail; they add to the task's spent
 * time ({@see Task::getLoggedMinutes()}) beside the TimeTrack timers. Each change is recorded in the task's history.
 */
final readonly class TaskTimeLogger
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private TaskTimeEntryRepositoryInterface $timeEntryRepository,
    ) {
    }

    public function log(Task $task, object $user, int $minutes, DateTimeImmutable $date, ?string $note = null): TaskTimeEntry
    {
        $note  = $note !== null && trim($note) !== '' ? trim($note) : null;
        $entry = new TaskTimeEntry($task, $user, $minutes, $date, $note);

        $task->addTimeEntry($entry);
        $this->changeRecorder->record($task, $user, TaskChangeType::TimeLogged, null, (string) $minutes, $date->format('Y-m-d'));
        $this->taskRepository->save($task);

        return $entry;
    }

    /**
     * Removes a manual entry of the task; false when the task has none with that id. Since 1.23.2 the entry is deleted
     * explicitly (no orphan removal on the task any more: deleting a task keeps its time).
     */
    public function remove(Task $task, string $entryId, object $user): bool
    {
        foreach ($task->getTimeEntries() as $entry) {
            if ($entry->getId() !== $entryId) {
                continue;
            }

            $task->removeTimeEntry($entry);
            // @igor-ignore - Repository write of this request's entry; the repository keeps no state of its own.
            $this->timeEntryRepository->remove($entry);
            $this->changeRecorder->record($task, $user, TaskChangeType::TimeRemoved, (string) $entry->getMinutes(), null, $entry->getLoggedAt()->format('Y-m-d'));
            $this->taskRepository->save($task);

            return true;
        }

        return false;
    }
}
