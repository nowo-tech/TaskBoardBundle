<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Since 2.1.0 (spec 214): the part of a task's bar drawn in one week, from the cell it starts in.
 */
final readonly class TaskCalendarChip
{
    public function __construct(
        public TaskCalendarEntry $entry,
        // Days it spans in this week, and its row among the bars of the week (from 1).
        public int $span,
        public int $lane,
        // Whether the task really starts or ends in this week: only then that edge can be dragged.
        public bool $startsHere,
        public bool $endsHere,
    ) {
    }
}
