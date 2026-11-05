<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\EventListener\TimeSpentAggregatorListener;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;

/**
 * Since 1.25: keeps `Task::$totalTimeSeconds` in step with **imported** TimeTrack entries (TimeTrack ≥ 1.11).
 *
 * - `TIME_ENTRY_IMPORTED` adds `duration − previousDuration` (an entry moved to another task leaves the old one and
 *   lands on the new one);
 * - `TIME_ENTRIES_REMOVED` subtracts the per-task sums the event carries (clamped at 0).
 *
 * Registered by the extension only when `Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent` exists, so TaskBoard keeps
 * working with TimeTrack 1.10 (no listener). Imported entries never fire timer events, so the
 * {@see TimeSpentAggregatorListener} never counts them twice.
 */
final readonly class ImportedTimeAggregatorListener
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function onImported(TimeEntryImportedEvent $event): void
    {
        $entry    = $event->getEntry();
        $previous = $event->getPreviousTaskId();
        if ($previous !== null && $previous !== $entry->getTaskId()) {
            $this->apply($previous, -$event->getPreviousDurationSeconds());
            $this->apply($entry->getTaskId(), $entry->getDurationSeconds());

            return;
        }

        $this->apply($entry->getTaskId(), $entry->getDurationSeconds() - $event->getPreviousDurationSeconds());
    }

    public function onRemoved(TimeEntriesRemovedEvent $event): void
    {
        foreach ($event->getSecondsByTask() as $taskId => $seconds) {
            $this->apply((string) $taskId, -$seconds);
        }
    }

    private function apply(string $taskId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task) {
            return;
        }

        $this->refreshBeforeMutating($task);
        $delta > 0 ? $task->addTimeSeconds($delta) : $task->subtractTimeSeconds(-$delta);
        $this->taskRepository->save($task);
    }

    /** As {@see TimeSpentAggregatorListener}: reload under a kept-alive worker. */
    private function refreshBeforeMutating(Task $task): void
    {
        $entityManager = $this->managerRegistry?->getManagerForClass(Task::class);
        if (!$entityManager instanceof EntityManagerInterface || !$entityManager->isOpen() || !$entityManager->contains($task)) {
            return;
        }

        $entityManager->refresh($task);
    }
}
