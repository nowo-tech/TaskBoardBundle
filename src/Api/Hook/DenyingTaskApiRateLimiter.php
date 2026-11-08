<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;

/**
 * Since 2.2.0 (spec 213): the default limiter: nothing passes until the host sets `api.hooks.rate_limiter`.
 */
final readonly class DenyingTaskApiRateLimiter implements TaskApiRateLimiterInterface
{
    public function consume(TaskApiPrincipal $principal): TaskApiRateLimitState
    {
        return new TaskApiRateLimitState(false, 0, 0, 0);
    }

    public function failedTokenState(string $clientIp): TaskApiRateLimitState
    {
        return new TaskApiRateLimitState(false, 0, 0, 0);
    }

    public function registerFailedToken(string $clientIp): void
    {
    }
}
