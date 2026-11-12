<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): one row of a task's history as the framework-free host ports see it, copied
 * when the port is called. The user is its id and its name snapshot.
 */
final readonly class TaskChangeSnapshot
{
    public function __construct(
        public string $id,
        public string $taskId,
        public string $boardId,
        public ?string $userId,
        public ?string $userName,
        public TaskChangeType $changeType,
        public ?string $oldValue,
        public ?string $newValue,
        public ?string $context,
        public ?string $cause,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
