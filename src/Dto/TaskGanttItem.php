<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Single row in a board Gantt timeline.
 */
final readonly class TaskGanttItem
{
    public function __construct(
        public string $id,
        public string $title,
        public string $startDate,
        public string $endDate,
        // Columns of the bar in the timeline's scale (since 1.15 slots, before: days).
        public int $startIndex,
        public int $endIndex,
        public int $progress,
        public string $priority,
        public ?string $columnName,
        public int $depth,
        public bool $completed,
        // Since 1.14: the task's colour (#rrggbb) for its bar; null keeps the priority colours.
        public ?string $color = null,
        // Since 1.15: estimate and time spent (minutes), spent as % of the estimate (null without one), the
        // deviation status (TaskDeviation::STATUS_*) and whether more was spent than estimated.
        public ?int $estimatedMinutes = null,
        public int $loggedMinutes = 0,
        public ?int $spentPercent = null,
        public string $deviationStatus = 'none',
        public bool $overrun = false,
        // Since 1.15: false when the bar is entirely outside a clamped window; clipped ends run past the window.
        public bool $visible = true,
        public bool $clippedStart = false,
        public bool $clippedEnd = false,
        // Since 1.15: H:i of the start and end when the task has a due time (hours scale), null for whole days.
        public ?string $startTime = null,
        public ?string $endTime = null,
    ) {
    }
}
