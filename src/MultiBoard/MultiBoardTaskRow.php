<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

/**
 * Since 2.13.0: one task of the multi-board list or export, as plain values read in one query (no entity, nothing lazy).
 */
final readonly class MultiBoardTaskRow
{
    /**
     * @param list<string> $labels the task's tags
     * @param list<MultiBoardPerson> $assignees
     */
    public function __construct(
        public string $taskId,
        public ?int $number,
        public string $title,
        public MultiBoardBoard $board,
        public ?string $columnName,
        public TaskPriority $priority,
        public ?DateTimeImmutable $startAt,
        public ?DateTimeImmutable $dueAt,
        public ?DateTimeImmutable $completedAt,
        public ?int $estimatedMinutes,
        public int $loggedMinutes,
        public array $labels,
        public array $assignees,
        public ?string $parentId,
        public bool $overdue,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->completedAt instanceof DateTimeImmutable;
    }
}
