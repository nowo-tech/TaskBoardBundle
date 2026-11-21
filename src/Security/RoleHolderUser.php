<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.29.0: a stand-in security user for a role check on a host user object that is not a Symfony `UserInterface`
 * ({@see UserRoleCheck}). It carries the user's id and role names and nothing else; it is not the application's user
 * class, so a voter that tests `instanceof` its own user class does not take it for one.
 *
 * @internal
 */
final readonly class RoleHolderUser implements UserInterface
{
    /**
     * @param non-empty-string $identifier
     * @param list<string> $roles
     */
    public function __construct(
        private string $identifier,
        private array $roles,
    ) {
    }

    public function getId(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    /** Nothing to erase (no credentials are held). */
    public function eraseCredentials(): void
    {
    }
}
