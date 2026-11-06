<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host user entity (`user_class`) of the functional kernel: the bundle entities map their user relations to it and the
 * security entity provider loads it by e-mail.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class AppUser implements UserInterface, Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Since 1.27.0: an optional display name (the People page's name pre-fill). */
    #[ORM\Column(name: 'display_name', length: 120, nullable: true)]
    private ?string $displayName = null;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
