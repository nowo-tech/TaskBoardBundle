<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use function is_scalar;
use function is_string;
use function method_exists;
use function trim;
use function ucfirst;

/**
 * Since 1.16: the name shown for a user (the same order as the member_label Twig macro): display name, full name,
 * e-mail, username, else "#id".
 */
final class UserLabel
{
    public static function of(object $user): string
    {
        foreach (['displayName', 'fullName', 'email', 'username', 'userIdentifier'] as $name) {
            $getter = 'get' . ucfirst($name);
            $value  = method_exists($user, $getter) ? $user->{$getter}() : ($user->{$name} ?? null);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $id = method_exists($user, 'getId') ? $user->getId() : null;

        return '#' . (is_scalar($id) ? (string) $id : '?');
    }
}
