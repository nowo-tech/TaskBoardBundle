<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

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
        $entry  = $event->getEntry();
        $taskId = $entry->getTaskId();
        $task   = $this->taskRepository->findById($taskId);

        if (!$task instanceof Task) {
            return;
        }

        $this->refreshBeforeMutating($task);

        $task->addTimeSeconds($entry->getDurationSeconds());
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
