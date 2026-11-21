<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function method_exists;

/**
 * Since 2.29.0 (audit B8): whether a given user (not the signed-in one) holds one of some roles, for the role rules of
 * {@see ConfigurableTaskBoardAccessChecker} (`security.access_roles` / `create_roles` / `list_roles`) and the
 * `security.manage_roles` bypass of {@see TaskBoardResourceAccessChecker}.
 *
 * - The user **is** the signed-in user (the token's user object, or an object with exactly the same `getId()`): the
 *   token decides, through `isGranted()`, as before 2.29.0 (impersonation, token-only roles and the role hierarchy apply).
 * - Any other user (a check on behalf of someone else, a Messenger handler, a console command, a worker without a
 *   token): Symfony's `isGrantedForUser()` decides with that user's own roles (`getRoles()`, role hierarchy and voters
 *   included). A user object that is not a Symfony `UserInterface` but has `getRoles()` is judged on those roles. A user
 *   without roles holds none.
 *
 * Before 2.29.0 the token decided for every user, so a signed-in administrator's roles were granted to whoever was
 * checked, and a user without a token (worker, console) was refused even when holding the role.
 *
 * Without a token storage (a service built by hand, without the `$tokenStorage` argument) the token decides for every
 * user, as up to 2.28: the bundle's container definitions always pass it.
 *
 * Stateless (FrankenPHP worker mode): reads the token at each call, keeps nothing.
 *
 * @internal
 */
final readonly class UserRoleCheck
{
    public function __construct(
        private ?AuthorizationCheckerInterface $authorizationChecker,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    public function hasAnyRole(object $user, array $roles): bool
    {
        if ($roles === [] || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return false;
        }

        if (!$this->tokenStorage instanceof TokenStorageInterface || $this->isSignedIn($user)) {
            foreach ($roles as $role) {
                if ($this->authorizationChecker->isGranted($role)) {
                    return true;
                }
            }

            return false;
        }

        $subject = $user instanceof UserInterface ? $user : $this->roleHolder($user);
        if (!$subject instanceof UserInterface) {
            return false;
        }

        foreach ($roles as $role) {
            if ($this->authorizationChecker instanceof UserAuthorizationCheckerInterface) {
                if ($this->authorizationChecker->isGrantedForUser($subject, $role)) {
                    return true;
                }
            } elseif (in_array($role, $subject->getRoles(), true)) {
                // A host checker without isGrantedForUser(): the user's own role names (no hierarchy).
                return true;
            }
        }

        return false;
    }

    /** Whether `$user` is the token's user: the same object, or exactly the same non-empty id. */
    private function isSignedIn(object $user): bool
    {
        $signedIn = $this->tokenStorage?->getToken()?->getUser();
        if (!is_object($signedIn)) {
            return false;
        }
        if ($signedIn === $user) {
            return true;
        }
        $id = ActorId::of($user);

        return $id !== null && $id === ActorId::of($signedIn);
    }

    /** A user object that is not a Symfony user but reports its roles, as a security user carrying only those roles. */
    private function roleHolder(object $user): ?UserInterface
    {
        if (!method_exists($user, 'getRoles')) {
            return null;
        }
        $reported = $user->getRoles();
        if (!is_array($reported)) {
            return null;
        }
        $roles = [];
        foreach ($reported as $role) {
            if (is_string($role) && $role !== '') {
                $roles[] = $role;
            }
        }

        $id = ActorId::of($user);

        return new RoleHolderUser($id !== null && $id !== '' ? $id : 'nowo_task_board.user', $roles);
    }
}
