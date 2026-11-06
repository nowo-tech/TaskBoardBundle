<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Adds the duration of every new TimeTrack entry to `Task::$totalTimeSeconds`.
 *
 * - `TIMER_STOP`: entries recorded from a timer ({@see __invoke()});
 * - Since 1.26.1, `TIME_ENTRY_CREATED` (TimeTrack ≥ 1.14): entries created without a timer, `manual` or `calendar`
 *   ({@see onTimeEntryCreated()}). Tagged by the extension only when that event exists, so TaskBoard keeps working with
 *   older TimeTrack.
 *
 * TimeTrack dispatches each entry through exactly one of the two events (a timer stop never creates a manual entry and
 * `POST entries` / an accepted calendar suggestion never stops a timer), so no entry is counted twice. Entries whose task
 * is not a TaskBoard task are ignored.
 */
#[AsEventListener(event: TimeTrackEvents::TIMER_STOP)]
final readonly class TimeSpentAggregatorListener
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function __invoke(TimerStopEvent $event): void
    {
        $this->addEntry($event->getEntry());
    }

    /** Since 1.26.1: a manual or calendar entry (TimeTrack ≥ 1.14, dispatched after its commit). */
    public function onTimeEntryCreated(TimeEntryCreatedEvent $event): void
    {
        $this->addEntry($event->getEntry());
    }

    private function addEntry(TimeEntry $entry): void
    {
        $seconds = $entry->getDurationSeconds();
        if ($seconds <= 0) {
            return;
        }
        $task = $this->taskRepository->findById($entry->getTaskId());
        if (!$task instanceof Task) {
            return;
        }

        $this->refreshBeforeMutating($task);

        $task->addTimeSeconds($seconds);
        $this->taskRepository->save($task);
    }

    /**
     * Under a long-running worker the task may still be in the identity map from an earlier
     * request; reloading avoids overwriting a concurrent total_time_seconds update.
     */
    private function refreshBeforeMutating(Task $task): void
    {
        $entityManager = $this->managerRegistry?->getManagerForClass(Task::class);
        if (!$entityManager instanceof EntityManagerInterface || !$entityManager->isOpen() || !$entityManager->contains($task)) {
            return;
        }

        $entityManager->refresh($task);
    }
}
