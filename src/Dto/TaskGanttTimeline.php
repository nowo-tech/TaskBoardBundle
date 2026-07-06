<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Timeline payload for the Gantt view.
 */
final readonly class TaskGanttTimeline
{
    /**
     * @param list<TaskGanttItem> $items
     * @param list<TaskGanttLink> $links
     * @param list<string> $dayKeys
     */
    public function __construct(
        public array $items,
        public array $links,
        public string $rangeStart,
        public string $rangeEnd,
        public array $dayKeys,
        public string $today,
    ) {
    }
}
