<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

/**
 * Form payload for creating or editing a task.
 */
final class TaskFormData
{
    /**
     * Since 2.2.1 (audit IB-L1): set by {@see fromTask()}. A null `columnId` then keeps the task's column (or none), so a
     * write of some fields never moves the task; otherwise null chooses the board's first column, as a form does.
     */
    public bool $keepsColumn = false;

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

    /**
     * Since 2.2.0 (spec 213): the task as it stands, for a write that changes only some fields.
     */
    public static function fromTask(Task $task): self
    {
        $data = new self(
            title: $task->getTitle(),
            description: $task->getDescription(),
            priority: $task->getPriority(),
            columnId: $task->getColumn()?->getId(),
            estimatedMinutes: $task->getEstimatedMinutes(),
            dueAt: $task->getDueAt(),
            tags: $task->getTags(),
            color: $task->getColor(),
            startAt: $task->getStartAt(),
        );
        $data->keepsColumn = true;

        return $data;
    }
}
