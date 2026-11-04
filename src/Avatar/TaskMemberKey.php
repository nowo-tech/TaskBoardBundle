<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Avatar;

use Nowo\TaskBoardBundle\Support\UserLabel;
use Stringable;

use function is_scalar;
use function method_exists;
use function property_exists;

/**
 * Since 1.23: the key of a user in {@see TaskMemberAvatarResolverInterface::urlsFor()} — its id as a string (`getId()`,
 * else a public `id` property, as {@see UserLabel}). Null for a user without an id.
 */
final class TaskMemberKey
{
    public static function of(object $user): ?string
    {
        $id = method_exists($user, 'getId') ? $user->getId() : (property_exists($user, 'id') ? ($user->id ?? null) : null);
        if (!is_scalar($id) && !$id instanceof Stringable) {
            return null;
        }
        $key = (string) $id;

        return $key === '' ? null : $key;
    }
}
