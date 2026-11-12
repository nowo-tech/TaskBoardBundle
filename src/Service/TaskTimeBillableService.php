<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskTimeBillableChangedEvent;
use Nowo\TaskBoardBundle\Exception\TaskTimeBillableRefusedException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.12.0 (Beacon D-20261111-12): changes the `billable` flag of a manual time entry — authorised and audited, unlike
 * {@see TaskTimeLogger}, which trusts its caller.
 *
 * Refused ({@see TaskTimeBillableRefusedException}) when the task has no such entry (`not_found`), when the entry's user was
 * deleted (`anonymised`: such an entry never changes), when the actor is neither its author nor a manager of the board or
 * cannot edit the task (`forbidden`, {@see TaskBoardResourceAccessChecker::canEditTimeEntry()}), and when the entry's
 * period is locked or waiting for approval (`period_locked`, {@see TaskTimePeriodLockInterface}: TimeTrack's timesheets when
 * it is installed). Each real change is recorded in the task's history (`time_billable`, old and new value, the entry's
 * day as context) and dispatches {@see TaskTimeBillableChangedEvent} after the save. Setting the value it already has
 * writes nothing.
 */
final readonly class TaskTimeBillableService
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private TaskBoardResourceAccessChecker $accessChecker,
        private TaskTimePeriodLockInterface $periodLock = new NoTaskTimePeriodLock(),
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * Sets the `billable` flag (null = unset) of the task's entry as the actor; returns the entry.
     *
     * @throws TaskTimeBillableRefusedException
     */
    public function change(Task $task, string $entryId, object $actor, ?bool $billable): TaskTimeEntry
    {
        $entry = null;
        foreach ($task->getTimeEntries() as $candidate) {
            if ($candidate->getId() === $entryId) {
                $entry = $candidate;
                break;
            }
        }
        if (!$entry instanceof TaskTimeEntry) {
            throw new TaskTimeBillableRefusedException(TaskTimeBillableRefusedException::NOT_FOUND);
        }
        $owner = $entry->getUser();
        if ($owner === null) {
            throw new TaskTimeBillableRefusedException(TaskTimeBillableRefusedException::ANONYMISED);
        }
        if (!$this->accessChecker->canEditTimeEntry($actor, $entry, $task)) {
            throw new TaskTimeBillableRefusedException(TaskTimeBillableRefusedException::FORBIDDEN);
        }
        if ($this->periodLock->isLocked($owner, $task->getId(), $task->getBoard()->getId(), $entry->getLoggedAt())) {
            throw new TaskTimeBillableRefusedException(TaskTimeBillableRefusedException::PERIOD_LOCKED);
        }

        $old = $entry->getBillable();
        if (!$entry->markBillable($billable)) {
            return $entry;
        }
        $this->changeRecorder->record($task, $actor, TaskChangeType::TimeBillable, $this->format($old), $this->format($billable), $entry->getLoggedAt()->format('Y-m-d'));
        $this->taskRepository->save($task);

        $board = $task->getBoard();
        $event = new TaskTimeBillableChangedEvent($entry->getId(), $task->getId(), $board->getId(), $board->getTenantKey(), ActorId::of($owner), ActorId::of($actor), $old, $billable, $this->changeRecorder->currentCause());
        if ($this->eventDispatcher instanceof TaskEventDeferral) {
            $this->eventDispatcher->dispatchSaved($event, TaskBoardEvents::TASK_TIME_BILLABLE_CHANGED);

            return $entry;
        }
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_TIME_BILLABLE_CHANGED);

        return $entry;
    }

    /** History value of a flag: `true`, `false` or null (unset). */
    private function format(?bool $billable): ?string
    {
        return $billable === null ? null : ($billable ? 'true' : 'false');
    }
}
