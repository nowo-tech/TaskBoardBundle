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
    ) {
    }
}
