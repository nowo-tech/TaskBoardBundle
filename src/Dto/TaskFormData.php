<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

/**
 * Form payload for creating or editing a task.
 */
final class TaskFormData
{
    public function __construct(
        public string $title = '',
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Normal,
        public ?string $columnId = null,
        public ?int $estimatedMinutes = null,
        public ?DateTimeImmutable $dueAt = null,
        /** @var list<string> */
        public array $tags = [],
        // Since 1.14: the task's colour (#rrggbb) or null.
        public ?string $color = null,
        // Since 1.15: the day work starts (the Gantt bar's start; null = the creation day).
        public ?DateTimeImmutable $startAt = null,
    ) {
    }
}
