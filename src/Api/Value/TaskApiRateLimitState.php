<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): a host's answer on a rate limit. `$resetAt`: when the window resets, Unix seconds.
 */
final readonly class TaskApiRateLimitState
{
    public function __construct(
        public bool $accepted,
        public int $limit,
        public int $remaining,
        public int $resetAt,
    ) {
    }
}
