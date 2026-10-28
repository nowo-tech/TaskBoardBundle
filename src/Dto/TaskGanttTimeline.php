<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\WeekStart;

/**
 * Timeline payload for the Gantt view.
 */
final readonly class TaskGanttTimeline
{
    /**
     * @param list<TaskGanttItem> $items
     * @param list<TaskGanttLink> $links
     * @param list<string> $dayKeys The start date (Y-m-d) of every column; one per day in the days scale
     * @param list<TaskGanttSlot> $slots Since 1.15: the columns in the timeline's scale
     */
    public function __construct(
        public array $items,
        public array $links,
        public string $rangeStart,
        public string $rangeEnd,
        public array $dayKeys,
        public string $today,
        public array $slots = [],
        public GanttScale $scale = GanttScale::Days,
        public WeekStart $weekStart = WeekStart::Monday,
        // Since 1.15: the plan is longer than the scale's maximum and only a window around today is drawn.
        public bool $clamped = false,
        // Since 1.15: the column holding now, null when now is outside the drawn range.
        public ?int $todayIndex = null,
    ) {
    }
}
