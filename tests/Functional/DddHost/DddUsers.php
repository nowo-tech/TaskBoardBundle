<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\User\TaskBoardUserDirectoryInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The DDD host's user store (in memory, no Doctrine): the security user provider, TaskBoard's user directory
 * (`user_directory`) and its assignee policy (the framework-free twin), all by id.
 *
 * @implements UserProviderInterface<DddUser>
 */
final class DddUsers implements UserProviderInterface, TaskBoardUserDirectoryInterface, TaskAssigneePolicyInterface, TaskApiRequestAuthenticatorInterface, ProviderCredentialSourceInterface, TaskApiUsersInterface
{
    /** @return array<string, DddUser> */
    public static function all(): array
    {
        return [
            'u-ana'   => new DddUser('u-ana', 'Ana Domain', 'ana@example.com'),
            'u-bruno' => new DddUser('u-bruno', 'Bruno Domain', 'bruno@example.com'),
            'U-ANA'   => new DddUser('U-ANA', 'Upper Ana', 'upper-ana@example.com'),
        ];
    }

    public function find(string $id): ?object
    {
        return self::all()[$id] ?? null;
    }

    public function assignableUsers(TaskBoardSnapshot $board): array
    {
        return array_values(self::all());
    }

    public function canBeAssigned(TaskBoardSnapshot $board, object $user): bool
    {
        return $user instanceof DddUser;
    }

    public function authenticate(TaskApiRequestContext $request): TaskApiPrincipal|TaskApiAuthFailure
    {
        return TaskApiAuthFailure::Invalid; // the look-alike test calls the API services in-process
    }

    public function apiId(object $user): string
    {
        return $user instanceof DddUser ? $user->getId() : '';
    }

    public function profiles(array $users, TaskApiPrincipal $viewer): array
    {
        return [];
    }

    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        return [];
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        return null;
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return null;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === DddUser::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        foreach (self::all() as $user) {
            if ($user->getUserIdentifier() === $identifier) {
                return $user;
            }
        }

        throw new UserNotFoundException();
    }
}
