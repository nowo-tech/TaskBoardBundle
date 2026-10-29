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

    /**
     * Since 1.16.1: a person picker option — the name, with the e-mail after it when the name is not the e-mail
     * ("Ada Lovelace (ada@example.com)"), so two people with the same name stay apart.
     */
    public static function option(object $user): string
    {
        $name  = self::of($user);
        $email = method_exists($user, 'getEmail') ? $user->getEmail() : ($user->email ?? null);
        if (!is_string($email) || trim($email) === '' || trim($email) === $name) {
            return $name;
        }

        return $name . ' (' . trim($email) . ')';
    }
}
