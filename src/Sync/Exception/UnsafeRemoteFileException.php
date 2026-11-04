<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Exception;

use Nowo\TaskBoardBundle\Sync\File\RemoteFileFetcher;
use RuntimeException;

/**
 * Since 1.23: a remote file was refused by {@see RemoteFileFetcher}. `reason` is a
 * stable code (`host_not_allowed`, `too_large`, `type_not_allowed`, `auth_required`, …); the message never holds the
 * URL.
 */
final class UnsafeRemoteFileException extends RuntimeException
{
    public const HOST_NOT_ALLOWED = 'host_not_allowed';
    public const INVALID_URL      = 'invalid_url';
    public const TOO_LARGE        = 'too_large';
    public const RUN_BUDGET       = 'run_budget';
    public const TYPE_NOT_ALLOWED = 'type_not_allowed';
    public const AUTH_REQUIRED    = 'auth_required';
    public const TOO_MANY_HOPS    = 'too_many_redirects';
    public const FAILED           = 'download_failed';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('The remote file was refused: ' . $reason . '.');
    }
}
