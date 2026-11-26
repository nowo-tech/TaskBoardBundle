<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use JsonException;

use function array_is_list;
use function base64_decode;
use function base64_encode;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function rtrim;
use function strlen;
use function strtr;
use function substr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 3.4.0 (client API v1): the opaque cursor of a list — base64url of `v1.<kind>.<JSON list of the sort key values>`,
 * the position after the last row a page scanned (its sort values and id). `<kind>` binds the cursor to the list and the
 * sort it came from: a cursor of another list or sort is `invalid_cursor`. Values are strings and integers only, so the
 * next query compares exactly what the last one read. Pure functions.
 *
 * @internal
 */
final class TaskClientCursor
{
    /** Longest cursor accepted (bytes). */
    public const MAX_LENGTH = 512;

    /**
     * @param list<int|string> $values
     */
    public static function encode(string $kind, array $values): string
    {
        return rtrim(strtr(base64_encode('v1.' . $kind . '.' . json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * The values of a cursor of this kind with `$count` values, else null (not a cursor, another kind, another shape).
     *
     * @return list<int|string>|null
     */
    public static function decode(string $cursor, string $kind, int $count): ?array
    {
        if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $prefix  = 'v1.' . $kind . '.';
        if ($decoded === false || !str_starts_with($decoded, $prefix)) {
            return null;
        }
        try {
            $values = json_decode(substr($decoded, strlen($prefix)), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($values) || !array_is_list($values) || count($values) !== $count) {
            return null;
        }
        foreach ($values as $value) {
            if (!is_int($value) && (!is_string($value) || strlen($value) > 191)) {
                return null;
            }
        }

        /* @var list<int|string> $values */
        return $values;
    }
}
