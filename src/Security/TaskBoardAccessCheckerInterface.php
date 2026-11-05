<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Global access control for TaskBoard manage UI.
 *
 * Since 1.24 a checker may implement {@see TenantAwareTaskBoardAccessCheckerInterface} instead, whose
 * canCreateBoard() also receives the tenant key of the request.
 */
interface TaskBoardAccessCheckerInterface
{
    public function canAccess(object $user): bool;

    public function canCreateBoard(object $user): bool;

    public function canListBoards(object $user): bool;
}
