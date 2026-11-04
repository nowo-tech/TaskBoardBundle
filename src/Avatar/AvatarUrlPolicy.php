<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Avatar;

use function preg_match;
use function str_starts_with;
use function strlen;

/**
 * Since 1.23: which avatar URLs may be rendered — `https://host[:port]/…` (no user info, no IPv6 literal, port 1–65535)
 * or a same-origin path that starts with exactly one "/". Everything else is null: other schemes (`http:`,
 * `javascript:`, `data:` of any kind, `blob:` …), `//`, backslashes, quotes, `<`, `>`, backticks, control characters or
 * white space (a trailing line feed included), and more than 2048 bytes. The front end repeats the same check
 * (`avatar-fallback.ts`); both run the table of `tests/Fixtures/avatar-urls.json`.
 */
final class AvatarUrlPolicy
{
    public const MAX_LENGTH = 2048;

    /** Characters a URL to print never needs (a valid URL has them percent-encoded). */
    private const FORBIDDEN = '/[\x00-\x20\x7F\\\\"\'<>`]/';

    /** `https://` + host (no "[", "@" or ":") + an optional port + the rest; D: "$" never matches before a final \n. */
    private const HTTPS = '/^https:\/\/[^\/?#@:\[\]]+(?::(\d{1,5}))?(?:[\/?#].*)?$/Di';

    public static function sanitize(?string $url): ?string
    {
        if ($url === null || $url === '' || strlen($url) > self::MAX_LENGTH || preg_match(self::FORBIDDEN, $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }
        if (preg_match(self::HTTPS, $url, $match) !== 1) {
            return null;
        }
        $port = $match[1] ?? '';

        return $port === '' || ((int) $port >= 1 && (int) $port <= 65535) ? $url : null;
    }
}
