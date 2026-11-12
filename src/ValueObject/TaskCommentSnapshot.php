<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use DateTimeImmutable;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): a task comment as the framework-free host ports see it, copied when the port
 * is called. The author is its id and its name snapshot.
 */
final readonly class TaskCommentSnapshot
{
    public function __construct(
        public string $id,
        public string $taskId,
        public string $boardId,
        public ?string $authorId,
        public ?string $authorName,
        public string $body,
        public ?string $cause,
        public bool $deleted,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $editedAt,
    ) {
    }
}
