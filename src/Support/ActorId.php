<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Stringable;

use function is_int;
use function is_string;
use function method_exists;

/**
 * Since 2.10.0 (DDD audit G2): the id a host user exposes through `getId()`, as a string, for the id-only event payloads:
 * an int, a non-empty string or a `Stringable` (a Symfony `Uuid` / `Ulid`, audit JY-L3). Null without a user, without
 * `getId()`, or for any other value (null, '', a float, a bool, an array).
 */
final class ActorId
{
    public static function of(?object $user): ?string
    {
        if ($user === null || !method_exists($user, 'getId')) {
            return null;
        }
        $id = $user->getId();
        if (is_int($id)) {
            return (string) $id;
        }
        $id = $id instanceof Stringable ? (string) $id : $id;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
