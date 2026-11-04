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
 * white space (a trailing line feed included), Unicode separators and other control or format characters, invalid UTF-8,
 * a port with a leading zero, and more than 2048 bytes (audit EG-I1: the rule UiKit and ChatKit share). The front end repeats the same check
 * (`avatar-fallback.ts`); both run the table of `tests/Fixtures/avatar-urls.json`.
 */
final class AvatarUrlPolicy
{
    public const MAX_LENGTH = 2048;

    /** Characters a URL to print never needs (a valid URL has them percent-encoded). */
    private const FORBIDDEN = '/[\x00-\x20\x7F\\\\"\'<>`]/';

    /** Unicode separators (U+00A0, U+2028 …) and control, format, surrogate or private-use characters. */
    private const UNICODE_FORBIDDEN = '/[\p{Z}\p{C}]/u';

    /** `https://` + host (no "[", "@" or ":") + an optional port (no leading zero) + the rest; D: no final \n. */
    private const HTTPS = '/^https:\/\/[^\/?#@:\[\]]+(?::([1-9]\d{0,4}))?(?:[\/?#].*)?$/Di';

    public static function sanitize(?string $url): ?string
    {
        if ($url === null || $url === '' || strlen($url) > self::MAX_LENGTH || preg_match(self::FORBIDDEN, $url) === 1
            || preg_match(self::UNICODE_FORBIDDEN, $url) !== 0) {
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
