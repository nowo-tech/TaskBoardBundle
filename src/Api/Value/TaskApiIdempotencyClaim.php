<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): the answer to a claim; the first answer's data with {@see TaskApiIdempotencyOutcome::Completed}.
 */
final readonly class TaskApiIdempotencyClaim
{
    public function __construct(
        public TaskApiIdempotencyOutcome $outcome,
        public ?int $status = null,
        public ?string $body = null,
        public ?string $tenantKey = null,
        public ?string $boardId = null,
    ) {
    }
}
