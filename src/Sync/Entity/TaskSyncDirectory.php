<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 1.25: the people directory of one remote workspace in one host scope (a project or an organization). Every
 * board and connection of that scope shares it, so one remote person maps to at most one local user per scope.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_directories')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_directories', columns: ['provider', 'scope_key', 'workspace_id'])]
class TaskSyncDirectory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'reviewed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $reviewedBy = null;

    #[ORM\Column(name: 'anonymous_counter', type: 'integer', options: ['default' => 0])]
    private int $anonymousCounter = 0;

    public function __construct(
        #[ORM\Column(type: 'string', length: 32)]
        private string $provider,
        #[ORM\Column(name: 'scope_key', type: 'string', length: 64)]
        private string $scopeKey,
        #[ORM\Column(name: 'workspace_id', type: 'string', length: 64)]
        private string $workspaceId,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    public function getWorkspaceId(): string
    {
        return $this->workspaceId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?object
    {
        return $this->reviewedBy;
    }

    /** The People step was saved. */
    public function markReviewed(?object $by, DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reviewedBy = $by;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reviewedAt = $at;

        return $this;
    }

    /** The next number of a "Former user #n". */
    public function nextAnonymousNumber(): int
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        return ++$this->anonymousCounter;
    }
}
