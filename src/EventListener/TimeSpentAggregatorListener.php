<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\EventListener;

use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: TimeTrackEvents::TIMER_STOP)]
final readonly class TimeSpentAggregatorListener
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
    ) {
    }

    public function __invoke(TimerStopEvent $event): void
    {
        $entry  = $event->getEntry();
        $taskId = $entry->getTaskId();
        $task   = $this->taskRepository->findById($taskId);

        if (!$task instanceof \Nowo\TaskBoardBundle\Entity\Task) {
            return;
        }

        $task->addTimeSeconds($entry->getDurationSeconds());
        $this->taskRepository->save($task);
    }
}
