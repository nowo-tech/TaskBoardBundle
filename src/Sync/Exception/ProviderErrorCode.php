<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Exception;

use function in_array;

/**
 * Since 1.23: why a provider call failed. Each code has one fixed English message and a translation key
 * `task_board.sync.error.<value>`.
 */
enum ProviderErrorCode: string
{
    case AuthFailed       = 'auth_failed';
    case Forbidden        = 'forbidden';
    case NotFound         = 'not_found';
    case RateLimited      = 'rate_limited';
    case Timeout          = 'timeout';
    case ResponseTooLarge = 'response_too_large';
    case InvalidResponse  = 'invalid_response';
    case ServerError      = 'server_error';
    case UnsafeUrl        = 'unsafe_url';
    case Transport        = 'transport';

    /** Since 1.27.0: it may work on the next try; any other error will answer the same. */
    public function isPassing(): bool
    {
        return in_array($this, [self::AuthFailed, self::RateLimited, self::Timeout, self::Transport, self::ServerError], true);
    }

    public function message(): string
    {
        return match ($this) {
            self::AuthFailed       => 'The provider refused the credential.',
            self::Forbidden        => 'The credential may not read this resource.',
            self::NotFound         => 'The provider did not find this resource.',
            self::RateLimited      => 'The provider asked to slow down.',
            self::Timeout          => 'The provider did not answer in time.',
            self::ResponseTooLarge => 'The provider answer is too large.',
            self::InvalidResponse  => 'The provider answer could not be read.',
            self::ServerError      => 'The provider failed.',
            self::UnsafeUrl        => 'The address is not allowed.',
            self::Transport        => 'The provider could not be reached.',
        };
    }
}
