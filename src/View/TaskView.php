<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;

/**
 * Since 2.29.0 (DDD-host checklist B1/B7, REQ-ARCH-001 rules 1 and 3): one task as the id-based host ports return it.
 * Ids, scalars and dates only: no entity, collection, user object or framework type.
 *
 * `revision` is the task's revision as read (the Task API's ETag): pass it back as the expected revision of
 * {@see TaskCommandsInterface::update()} to refuse a save over a change made meanwhile (REQ-RUNTIME-003 rule 4).
 */
final readonly class TaskView
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $boardId,
        public ?string $tenantKey,
        public string $title,
        public ?string $description,
        public ?int $number,
        public ?string $columnId,
        public ?string $parentId,
        public ?string $creatorId,
        public TaskPriority $priority,
        public ?int $estimatedMinutes,
        public ?string $color,
        public array $tags,
        public ?DateTimeImmutable $startAt,
        public ?DateTimeImmutable $dueAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $archivedAt,
        public int $totalTimeSeconds,
        public int $revision,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->completedAt instanceof DateTimeImmutable;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof DateTimeImmutable;
    }
}
