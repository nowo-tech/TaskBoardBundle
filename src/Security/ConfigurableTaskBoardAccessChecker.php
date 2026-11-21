<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default role-based access checker driven by nowo_task_board.security.* config.
 *
 * Since 2.29.0 the roles are those of the user it is asked about ({@see UserRoleCheck}): the signed-in user is judged
 * by the token as before, any other user by their own roles. Before, the token decided for every user, so a signed-in
 * administrator granted access to whoever was checked, and a user checked without a token (Messenger, console) was
 * refused.
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
        // Since 2.29.0: to tell the signed-in user from any other user (null, a hand-built instance: the token decides, as before).
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function canAccess(object $user): bool
    {
        return $this->hasAnyRole($user, $this->accessRoles);
    }

    /**
     * `security.create_roles`; the tenant is ignored (since 1.24 the signature takes it, the default rule is global).
     */
    public function canCreateBoard(object $user, ?string $tenantKey = null): bool
    {
        return $this->hasAnyRole($user, $this->createRoles);
    }

    /**
     * `security.list_roles`; the tenant is ignored, as in {@see self::canCreateBoard()}.
     */
    public function canListBoards(object $user, ?string $tenantKey = null): bool
    {
        return $this->hasAnyRole($user, $this->listRoles);
    }

    /** @param list<string> $roles */
    private function hasAnyRole(object $user, array $roles): bool
    {
        return (new UserRoleCheck($this->authorizationChecker, $this->tokenStorage))->hasAnyRole($user, $roles);
    }
}
