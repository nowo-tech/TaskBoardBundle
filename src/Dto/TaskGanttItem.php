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
        public int $startIndex,
        public int $endIndex,
        public int $progress,
        public string $priority,
        public ?string $columnName,
        public int $depth,
        public bool $completed,
    ) {
    }
}
