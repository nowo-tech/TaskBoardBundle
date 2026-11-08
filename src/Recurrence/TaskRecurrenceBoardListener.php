<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Copy\TaskColumnRule;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardChangedEvent;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;

/**
 * Since 2.3.0 (specs 215 and 217): a series goes with its latest occurrence when that task moves to another board; its
 * start column is mapped by the column rule. A series whose latest occurrence stays does not move.
 */
final readonly class TaskRecurrenceBoardListener
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(TaskBoardChangedEvent $event): void
    {
        $target = $this->entityManager->find(TaskBoard::class, $event->targetBoardId);
        if (!$target instanceof TaskBoard) {
            return;
        }
        $series = $this->entityManager->getRepository(TaskRecurrence::class)->findBy(['latestTask' => $event->taskIds]);
        foreach ($series as $one) {
            $one->moveTo($target, TaskColumnRule::target($target, $one->getStartColumn(), false));
        }
        if ($series !== []) {
            $this->entityManager->flush();
        }
    }
}
