<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_task_board.security.* config.
 */
final readonly class ConfigurableTaskBoardAccessChecker implements TenantAwareTaskBoardAccessCheckerInterface
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

    /**
     * `security.create_roles`; the tenant is ignored (since 1.24 the signature takes it, the default rule is global).
     */
    public function canCreateBoard(object $user, ?string $tenantKey = null): bool
    {
        return $this->hasAnyRole($this->createRoles);
    }

    /**
     * `security.list_roles`; the tenant is ignored, as in {@see self::canCreateBoard()}.
     */
    public function canListBoards(object $user, ?string $tenantKey = null): bool
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
