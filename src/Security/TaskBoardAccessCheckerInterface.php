<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Global access control for TaskBoard manage UI.
 */
interface TaskBoardAccessCheckerInterface
{
    public function canAccess(object $user): bool;

    public function canCreateBoard(object $user): bool;

    public function canListBoards(object $user): bool;
}
