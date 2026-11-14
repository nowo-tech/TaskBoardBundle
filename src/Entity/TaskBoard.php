<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderManager;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Support\BoardIcon;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use SortDirection;

#[ORM\Entity]
#[ORM\Table(name: 'task_board_boards')]
#[ORM\Index(name: 'idx_task_board_boards_tenant', columns: ['tenant_key'])]
// Since 1.30.0: unique per tenant. A global index would tell one tenant that another tenant has a slug.
#[ORM\UniqueConstraint(name: 'uniq_task_board_boards_tenant_slug', columns: ['tenant_key', 'slug'])]
class TaskBoard
{
    public const FORMAT_MARKDOWN = 'markdown';

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

    /** Since 1.25 (audit FP-L7): the creator's name, kept when the account is erased (`creator_id` SET NULL). */
    #[ORM\Column(name: 'creator_name', type: 'string', length: 180, nullable: true)]
    private ?string $creatorName;

    /** Since 1.30.0 (spec 211): one emoji shown before the name ({@see BoardIcon}). */
    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $icon = null;

    /** Since 1.30.0 (spec 211): {@see self::FORMAT_MARKDOWN}; null is plain text, as every board before it. */
    #[ORM\Column(name: 'description_format', type: 'string', length: 16, nullable: true)]
    private ?string $descriptionFormat = null;

    /**
     * Since 2.18.0 (Beacon spec 234, FR-031): the folder the board is in, of the same tenant; null: a folderless board.
     * Deleting a folder needs it empty ({@see TaskBoardFolderManager}); SET NULL is a safety net only.
     */
    #[ORM\ManyToOne(targetEntity: TaskBoardFolder::class)]
    #[ORM\JoinColumn(name: 'folder_id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskBoardFolder $folder = null;

    /** @var Collection<int, BoardColumn> */
    #[ORM\OneToMany(targetEntity: BoardColumn::class, mappedBy: 'board', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    private Collection $columns;

    /**
     * Since 1.25 no orphan removal (audit FA-L1): removing a task from this collection inside a flush skipped the task's
     * `PreRemove` time detach. Delete tasks with `EntityManager::remove()` (or {@see TaskBoardEraser});
     * the `remove` cascade of a board delete still detaches every task's time.
     *
     * @var Collection<int, Task>
     */
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'board', cascade: ['persist', 'remove'])]
    private Collection $tasks;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        private string $name,
        #[ORM\Column(type: 'string', length: 255)]
        private string $slug,
        // Since 1.25 (audit FP-L7): nullable, ON DELETE SET NULL; deleting the creator's account keeps the board.
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?object $creator,
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creatorName = $creator === null ? null : Text::cut(UserLabel::of($creator), Task::USER_NAME_MAX);
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

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    /**
     * @throws InvalidArgumentException when it is not one emoji of at most {@see BoardIcon::MAX_BYTES} bytes
     */
    public function setIcon(?string $icon): self
    {
        if ($icon !== null && !BoardIcon::isValid($icon)) {
            throw new InvalidArgumentException('A board icon is one emoji of at most ' . BoardIcon::MAX_BYTES . ' bytes.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->icon = $icon;

        return $this;
    }

    public function getDescriptionFormat(): ?string
    {
        return $this->descriptionFormat;
    }

    public function setDescriptionFormat(?string $format): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->descriptionFormat = $format === self::FORMAT_MARKDOWN ? self::FORMAT_MARKDOWN : null;

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

    /** The creator; null once the account was erased (since 1.25, see {@see self::getCreatorName()}). */
    public function getCreator(): ?object
    {
        return $this->creator;
    }

    /** Since 1.25: the creator's name when the board was created (kept when the account goes). */
    public function getCreatorName(): ?string
    {
        return $this->creatorName;
    }

    /**
     * Since 1.25 (audit FP-L7): the account is being erased — the board keeps its name snapshot (null with
     * `$forgetName`) and no longer points at the user, so deleting the user deletes no board.
     *
     * @internal used by the account erasure helper
     */
    public function detachCreator(bool $forgetName = false): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creator = null;
        if ($forgetName) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->creatorName = null;
        }

        return $this;
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

    /**
     * Since 2.15.1 (REQ-SEC-006 item 1): a key that cannot be matched exactly (edge whitespace, control or format
     * characters, invalid UTF-8, more than 191 characters) is refused, never trimmed.
     *
     * @throws InvalidArgumentException
     */
    public function setTenantKey(?string $tenantKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->tenantKey = ExactKey::assertUsable($tenantKey, 'tenant key');

        return $this;
    }

    /** Since 2.18.0 (FR-031): null for a folderless board. */
    public function getFolder(): ?TaskBoardFolder
    {
        return $this->folder;
    }

    /** Since 2.18.0 (FR-031): the folder's id, null for a folderless board. */
    public function getFolderId(): ?string
    {
        return $this->folder?->getId();
    }

    /**
     * Since 2.18.0 (FR-031): puts the board in a folder of its own tenant, or out of any (null).
     *
     * @internal through {@see TaskBoardFolderManager::moveBoard()}, which checks the tenant and the right
     *
     * @throws InvalidArgumentException when the folder belongs to another tenant
     */
    public function changeFolder(?TaskBoardFolder $folder): self
    {
        if ($folder instanceof TaskBoardFolder && $folder->getTenantKey() !== $this->tenantKey) {
            throw new InvalidArgumentException('A board goes only into a folder of its own tenant.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->folder = $folder;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

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
