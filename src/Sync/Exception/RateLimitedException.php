<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Exception;

/**
 * Since 1.23: the provider asked to wait longer than the adapter waits inline.
 */
final class RateLimitedException extends ProviderException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(ProviderErrorCode::RateLimited);
    }
}
