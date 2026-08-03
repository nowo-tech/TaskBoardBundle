<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllTaskBoardAccessChecker implements TaskBoardAccessCheckerInterface
{
    public function canAccess(object $user): bool
    {
        return true;
    }

    public function canCreateBoard(object $user): bool
    {
        return true;
    }

    public function canListBoards(object $user): bool
    {
        return true;
    }
}
