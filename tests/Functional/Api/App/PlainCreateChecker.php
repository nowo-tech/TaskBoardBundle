<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;

/**
 * A host's access checker from before tenants (not tenant-aware): it lets everyone in and refuses every create.
 */
final class PlainCreateChecker implements TaskBoardAccessCheckerInterface
{
    public function canAccess(object $user): bool
    {
        return true;
    }

    public function canCreateBoard(object $user): bool
    {
        return false;
    }

    public function canListBoards(object $user): bool
    {
        return true;
    }
}
