<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;

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
     * Removes a manual entry of the task; false when the task has none with that id.
     */
    public function remove(Task $task, string $entryId, object $user): bool
    {
        foreach ($task->getTimeEntries() as $entry) {
            if ($entry->getId() !== $entryId) {
                continue;
            }

            $task->removeTimeEntry($entry);
            $this->changeRecorder->record($task, $user, TaskChangeType::TimeRemoved, (string) $entry->getMinutes(), null, $entry->getLoggedAt()->format('Y-m-d'));
            $this->taskRepository->save($task);

            return true;
        }

        return false;
    }
}
