<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 1.20: previous / next of a task on its task page and panel.
 *
 * - `order`: the sequence `position` follows, {@see self::ORDER_BOARD} (columns left to right, then the cards of each
 *   column top to bottom) or {@see self::ORDER_LIST} (the list view's rows);
 * - `position`: the task in that order (null when the view does not show it, e.g. a subtask on a board that only shows
 *   top-level cards);
 * - `siblings`: a subtask among its parent's subtasks (null for a top-level task), `parent` that parent;
 * - `firstSubtask`: the way into the task's own subtasks (null without any).
 */
final readonly class TaskNavigation
{
    public const ORDER_BOARD = 'board';
    public const ORDER_LIST  = 'list';

    public function __construct(
        public string $order,
        public ?TaskPosition $position,
        public ?TaskPosition $siblings = null,
        public ?Task $parent = null,
        public ?Task $firstSubtask = null,
    ) {
    }

    /**
     * The query that keeps the order on the next page (`from=list`); empty for the default board order.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return $this->order === self::ORDER_BOARD ? [] : ['from' => $this->order];
    }

    /** True when there is anything to show (a neighbour, a parent or a subtask). */
    public function isEmpty(): bool
    {
        return !$this->position instanceof TaskPosition && !$this->siblings instanceof TaskPosition && !$this->firstSubtask instanceof Task;
    }
}
