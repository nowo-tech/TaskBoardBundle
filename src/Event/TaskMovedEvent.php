<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * A task changed column — {@see TaskBoardEvents::TASK_MOVED} (a reorder inside the same column is not a move).
 */
final class TaskMovedEvent extends AbstractTaskEvent
{
    /**
     * @param BoardColumn|null $fromColumn Column before the move (null: none)
     * @param BoardColumn|null $toColumn Column after the move (null: none)
     */
    public function __construct(
        Task $task,
        object $actor,
        private readonly ?BoardColumn $fromColumn,
        private readonly ?BoardColumn $toColumn,
    ) {
        parent::__construct($task, $actor);
    }

    public function getFromColumn(): ?BoardColumn
    {
        return $this->fromColumn;
    }

    public function getToColumn(): ?BoardColumn
    {
        return $this->toColumn;
    }
}
