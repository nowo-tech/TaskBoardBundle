<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A DDD host's security user: a plain PHP object, NOT a Doctrine entity (no mapping anywhere).
 */
final readonly class DddUser implements UserInterface
{
    public function __construct(
        private string $id,
        private string $displayName,
        private string $email,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }
}
