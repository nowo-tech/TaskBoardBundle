<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;

/**
 * Since 2.2.0 (spec 213): the limits on the API: per token, and failed tokens per IP (FR-006).
 * Default: {@see DenyingTaskApiRateLimiter}, every request 429.
 */
interface TaskApiRateLimiterInterface
{
    /** Counts one request of the token. */
    public function consume(TaskApiPrincipal $principal): TaskApiRateLimitState;

    /** Whether the IP may try a token; it counts nothing. */
    public function failedTokenState(string $clientIp): TaskApiRateLimitState;

    public function registerFailedToken(string $clientIp): void;
}
