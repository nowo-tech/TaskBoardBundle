<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function is_scalar;
use function method_exists;

/**
 * Since 2.10.0 (DDD audit G2): the id a host user exposes through `getId()`, as a string, for the id-only event payloads.
 * Null without a user, or when the user has no scalar `getId()`.
 */
final class ActorId
{
    public static function of(?object $user): ?string
    {
        if ($user === null || !method_exists($user, 'getId')) {
            return null;
        }
        $id = $user->getId();

        return is_scalar($id) ? (string) $id : null;
    }
}
