<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use InvalidArgumentException;

use function in_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * Since 2.15.1 (REQ-SEC-006, audit F5): a string key the bundle stores and looks rows up by — a tenant key, a board slug,
 * an import external id, a task-number scope — is matched byte for byte. The database may fold it (a `*_ci` collation,
 * PAD SPACE, SQLite NOCASE): every lookup keeps only the rows whose key is `===` the one asked, and a key the bundle could
 * not keep exactly is refused where it enters ({@see self::isUsable()}).
 */
final class ExactKey
{
    /** The length of the key columns (`tenant_key`, `scope_key`). */
    public const MAX_LENGTH = 191;

    /** Rows per `IN (...)` list, far below the 65,535 bound parameters PostgreSQL (and MySQL) accept in one statement. */
    public const CHUNK = 500;

    /**
     * Whether a key can be kept and matched exactly. Refused: empty, longer than `$maxLength` characters, invalid UTF-8,
     * whitespace (Unicode, NBSP included) at either edge, and control or format characters anywhere (ZWSP, BOM, bidi
     * marks...). A PAD SPACE collation takes `"acme "` for `"acme"`, and an invisible character makes two keys look alike.
     */
    public static function isUsable(string $key, int $maxLength = self::MAX_LENGTH): bool
    {
        if ($key === '' || preg_match('//u', $key) !== 1 || mb_strlen($key) > $maxLength) {
            return false;
        }

        return preg_match('/^[\s\p{Z}]|[\s\p{Z}]$|\p{C}/u', $key) !== 1;
    }

    /**
     * Refuses a key that cannot be matched exactly ({@see self::isUsable()}); null stays null.
     *
     * @throws InvalidArgumentException
     */
    public static function assertUsable(?string $key, string $what, int $maxLength = self::MAX_LENGTH): ?string
    {
        if ($key !== null && !self::isUsable($key, $maxLength)) {
            throw new InvalidArgumentException(sprintf('TaskBoard: the %s must be valid UTF-8 of 1 to %d characters, without whitespace at its edges and without control or format characters.', $what, $maxLength));
        }

        return $key;
    }

    /** Whether a value read back from the database is exactly `$key` (null matches only null). */
    public static function same(mixed $stored, ?string $key): bool
    {
        return $key === null ? $stored === null : is_string($stored) && $stored === $key;
    }

    /**
     * Whether a value read back from the database is exactly one of `$keys`.
     *
     * @param list<string> $keys
     */
    public static function oneOf(mixed $stored, array $keys): bool
    {
        return is_string($stored) && in_array($stored, $keys, true);
    }
}
