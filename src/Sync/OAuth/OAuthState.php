<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use InvalidArgumentException;

use function base64_encode;
use function hash_equals;
use function is_string;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Since 3.1.0 (CU-1, Q-CU-3: a copy of TimeTrack's `CalendarOAuthState`, no TimeTrack dependency): the OAuth `state`
 * of one authorization flow. ClickUp has no PKCE, so this single-use value is the CSRF protection of the callback:
 *
 * 1. the host's start route calls {@see self::create()} and keeps {@see self::$value} in the session together with the
 *    actor, the tenant, the purpose and the return path (TTL 600 s);
 * 2. the callback rebuilds it with {@see self::fromString()}, compares the query's `state` with {@see self::matches()}
 *    (constant time) and removes it from the session whatever the result (single use).
 *
 * Immutable, no service state (FrankenPHP worker-safe).
 */
final readonly class OAuthState
{
    /** 32 random bytes as base64url without padding: exactly 43 characters. */
    public const PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    private function __construct(public string $value)
    {
    }

    /** A new random state (32 bytes from the CSPRNG). */
    public static function create(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /**
     * A state the host stored earlier.
     *
     * @throws InvalidArgumentException when it does not have the shape of {@see self::create()}
     */
    public static function fromString(string $value): self
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('The OAuth state is malformed.');
        }

        return new self($value);
    }

    /** Whether a value has the shape of {@see self::create()} (no trailing newline: `/D`). */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /** Whether the callback's `state` is this one (constant time; false for a missing or non-string value). */
    public function matches(mixed $received): bool
    {
        return is_string($received) && hash_equals($this->value, $received);
    }
}
