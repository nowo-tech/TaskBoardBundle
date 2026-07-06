<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_task_board.security.* config.
 */
final readonly class ConfigurableTaskBoardAccessChecker implements TaskBoardAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     * @param list<string> $createRoles
     * @param list<string> $listRoles
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private array $createRoles,
        private array $listRoles,
    ) {
    }

    public function canAccess(object $user): bool
    {
        return $this->hasAnyRole($this->accessRoles);
    }

    public function canCreateBoard(object $user): bool
    {
        return $this->hasAnyRole($this->createRoles);
    }

    public function canListBoards(object $user): bool
    {
        return $this->hasAnyRole($this->listRoles);
    }

    /** @param list<string> $roles */
    private function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
