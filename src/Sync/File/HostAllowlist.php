<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\File;

use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Since 1.23: whether a host is on a provider's attachment allowlist — an exact host, or "*.suffix" for one or more
 * extra labels in front of the suffix (never the bare suffix). Hosts are compared lower-cased; non-ASCII hosts never match.
 */
final readonly class HostAllowlist
{
    /**
     * @param list<string> $patterns
     */
    public static function allows(array $patterns, string $host): bool
    {
        $host = self::normalize($host);
        if ($host === null) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = strtolower(rtrim($pattern, '.'));
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);
                if (strlen($host) > strlen($suffix) && str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($pattern === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * The host lower-cased without a trailing dot; null for anything but an ASCII host name (an internationalized
     * host is never on an allowlist, so it is refused rather than converted).
     */
    private static function normalize(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));

        return preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/D', $host) === 1 ? $host : null;
    }
}
