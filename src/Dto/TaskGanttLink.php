<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Dependency edge between two Gantt rows.
 */
final readonly class TaskGanttLink
{
    public function __construct(
        public string $fromId,
        public string $toId,
        public string $type,
    ) {
    }
}
