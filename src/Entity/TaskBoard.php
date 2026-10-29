<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use SortDirection;

#[ORM\Entity]
#[ORM\Table(name: 'task_board_boards')]
#[ORM\Index(name: 'idx_task_board_boards_tenant', columns: ['tenant_key'])]
class TaskBoard
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    /** Host tenant the board belongs to (`tenancy`); null: a board outside any tenant. */
    #[ORM\Column(name: 'tenant_key', type: 'string', length: 191, nullable: true)]
    private ?string $tenantKey = null;

    /** @var Collection<int, BoardColumn> */
    #[ORM\OneToMany(targetEntity: BoardColumn::class, mappedBy: 'board', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    private Collection $columns;

    /** @var Collection<int, Task> */
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'board', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tasks;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        private string $name,
        #[ORM\Column(type: 'string', length: 255, unique: true)]
        private string $slug,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $description = null,
        #[ORM\ManyToOne(targetEntity: Team::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?Team $team = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->name      = Text::cut($name, 255);
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->columns   = new ArrayCollection();
        $this->tasks     = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = Text::cut($name, 255);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->slug = $slug;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $description;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreator(): object
    {
        return $this->creator;
    }

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(?Team $team): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->team = $team;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function setTenantKey(?string $tenantKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->tenantKey = $tenantKey;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function archive(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->archivedAt = new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function restore(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->archivedAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof DateTimeImmutable;
    }

    /** @return Collection<int, BoardColumn> */
    public function getColumns(): Collection
    {
        return $this->columns;
    }

    public function addColumn(BoardColumn $column): self
    {
        if (!$this->columns->contains($column)) {
            $this->columns->add($column);
            $column->setBoard($this);
        }

        return $this;
    }

    /**
     * Removes a column (orphan removal deletes it on the next flush); since 1.11.
     */
    public function removeColumn(BoardColumn $column): self
    {
        $this->columns->removeElement($column);

        return $this;
    }

    /** @return Collection<int, Task> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }
}
