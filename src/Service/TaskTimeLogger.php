<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskTimeLoggedEvent;
use Nowo\TaskBoardBundle\Event\TaskTimeRemovedEvent;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskTimeEntryRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function trim;

/**
 * Since 1.15: manual time entries (minutes, date, note) logged from the task detail; they add to the task's spent
 * time ({@see Task::getLoggedMinutes()}) beside the TimeTrack timers. Each change is recorded in the task's history.
 * Since 2.11.0 (DDD audit G3): after each saved change it dispatches {@see TaskBoardEvents::TASK_TIME_LOGGED} /
 * {@see TaskBoardEvents::TASK_TIME_REMOVED} (ids only), through {@see TaskEventDeferral}.
 */
final readonly class TaskTimeLogger implements TaskTimeLoggerInterface
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private TaskTimeEntryRepositoryInterface $timeEntryRepository,
        // Since 2.11.0 (DDD audit G3): dispatches TASK_TIME_LOGGED / TASK_TIME_REMOVED (none: no event).
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function log(Task $task, object $user, int $minutes, DateTimeImmutable $date, ?string $note = null): TaskTimeEntry
    {
        $note  = $note !== null && trim($note) !== '' ? trim($note) : null;
        $entry = new TaskTimeEntry($task, $user, $minutes, $date, $note);

        $task->addTimeEntry($entry);
        $this->changeRecorder->record($task, $user, TaskChangeType::TimeLogged, null, (string) $minutes, $date->format('Y-m-d'));
        $this->taskRepository->save($task);
        $this->dispatchSaved(TaskTimeLoggedEvent::of($task, $entry, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_TIME_LOGGED);

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

            $event = TaskTimeRemovedEvent::of($task, $entry, $user, $this->changeRecorder->currentCause());
            $task->removeTimeEntry($entry);
            // @igor-ignore - Repository write of this request's entry; the repository keeps no state of its own.
            $this->timeEntryRepository->remove($entry);
            $this->changeRecorder->record($task, $user, TaskChangeType::TimeRemoved, (string) $entry->getMinutes(), null, $entry->getLoggedAt()->format('Y-m-d'));
            $this->taskRepository->save($task);
            $this->dispatchSaved($event, TaskBoardEvents::TASK_TIME_REMOVED);

            return true;
        }

        return false;
    }

    /** Since 2.11.0: the event of a saved write; a listener failure is logged, not rethrown (audit KO-T-L2). */
    private function dispatchSaved(object $event, string $eventName): void
    {
        if ($this->eventDispatcher instanceof TaskEventDeferral) {
            $this->eventDispatcher->dispatchSaved($event, $eventName);

            return;
        }
        $this->eventDispatcher?->dispatch($event, $eventName);
    }
}
