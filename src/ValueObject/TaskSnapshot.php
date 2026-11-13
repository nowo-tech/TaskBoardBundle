<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): a task as the framework-free host ports see it, copied when the port is
 * called. Ids instead of the board, column, parent and creator objects; no collections.
 */
final readonly class TaskSnapshot
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $boardId,
        public ?string $tenantKey,
        public string $title,
        public ?int $number,
        public ?string $columnId,
        public ?string $parentId,
        public ?string $creatorId,
        public ?string $creatorName,
        public TaskPriority $priority,
        public bool $completed,
        public bool $archived,
        public ?DateTimeImmutable $startAt,
        public ?DateTimeImmutable $dueAt,
        public array $tags,
        public ?string $externalId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
