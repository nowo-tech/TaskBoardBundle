<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use Nowo\TaskBoardBundle\Sync\Hook\OAuthConnectionStartInterface;

use function preg_match;
use function strlen;

/**
 * Since 3.1.0 (CU-1): the check a host's OAuth start route applies to the `returnTo` it receives (from
 * {@see OAuthConnectionStartInterface::connectUrl()}) before it keeps it in the
 * session: a same-origin path only (one leading "/", no scheme, host, backslash, white space or control character),
 * so the callback cannot become an open redirect. Pure function.
 */
final class OAuthReturnPath
{
    public const MAX_LENGTH = 2048;

    public static function isSafe(string $returnTo): bool
    {
        return strlen($returnTo) <= self::MAX_LENGTH && preg_match('~^/(?![/\\\\])[^\s\x00-\x1f\x7f\\\\]*$~D', $returnTo) === 1;
    }
}
