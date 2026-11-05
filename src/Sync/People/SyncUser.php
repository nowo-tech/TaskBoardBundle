<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Normalizer;
use Stringable;

use function class_exists;
use function is_scalar;
use function mb_strtolower;
use function method_exists;
use function trim;

/**
 * Since 1.25: small helpers of the people services — a user's id and the normalised form of an e-mail.
 */
final class SyncUser
{
    /** The user's id as a string; null when the object has none. */
    public static function id(?object $user): ?string
    {
        if ($user === null || !method_exists($user, 'getId')) {
            return null;
        }
        $id = $user->getId();

        return is_scalar($id) || $id instanceof Stringable ? (string) $id : null;
    }

    /** Whether both objects are the same user (same instance or same id). */
    public static function same(?object $a, ?object $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return $a === $b || (self::id($a) !== null && self::id($a) === self::id($b));
    }

    /** Trimmed, NFC (when ext-intl is loaded) and lower-cased; null for an empty value. */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }
        if (class_exists(Normalizer::class)) {
            $email = (string) Normalizer::normalize($email, Normalizer::FORM_C);
        }

        return mb_strtolower($email);
    }
}
