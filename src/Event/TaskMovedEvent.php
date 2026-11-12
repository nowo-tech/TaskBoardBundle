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

    /**
     * @deprecated since 2.15.0, use {@see self::getFromColumnId()}; removed in 3.0
     */
    public function getFromColumn(): ?BoardColumn
    {
        return $this->fromColumn;
    }

    /**
     * @deprecated since 2.15.0, use {@see self::getToColumnId()}; removed in 3.0
     */
    public function getToColumn(): ?BoardColumn
    {
        return $this->toColumn;
    }

    /** Since 2.10.0 (DDD audit G2). */
    public function getFromColumnId(): ?string
    {
        return $this->fromColumn?->getId();
    }

    /** Since 2.10.0 (DDD audit G2). */
    public function getToColumnId(): ?string
    {
        return $this->toColumn?->getId();
    }
}
