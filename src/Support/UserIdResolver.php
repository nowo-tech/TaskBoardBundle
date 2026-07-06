<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserIdResolver
{
    public static function getId(UserInterface $user): string
    {
        if (!method_exists($user, 'getId')) {
            throw new LogicException('User entity must expose getId().');
        }

        return (string) $user->getId();
    }
}
