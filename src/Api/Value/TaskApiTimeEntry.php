<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use DateTimeImmutable;

/**
 * Since 2.2.0 (spec 213): a time entry as the host's time store answers it.
 */
final readonly class TaskApiTimeEntry
{
    public function __construct(
        public string $id,
        public string $userApiId,
        public DateTimeImmutable $start,
        public int $durationSeconds,
        public ?string $taskId = null,
        public ?string $description = null,
    ) {
    }
}
