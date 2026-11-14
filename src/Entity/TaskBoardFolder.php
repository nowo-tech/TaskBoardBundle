<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Folder\FolderStatusSets;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_map;
use function array_values;

/**
 * Since 2.18.0 (Beacon spec 234, FR-031): a folder groups boards of one tenant — a name, a Markdown description and an
 * order among the tenant's folders. A board points at its folder ({@see TaskBoard::getFolder()}); a folder is deleted only
 * when no board is in it. `tenantKey` null: a folder outside any tenant, for boards without one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_folders')]
#[ORM\Index(name: 'idx_task_board_folders_tenant', columns: ['tenant_key'])]
class TaskBoardFolder
{
    public const NAME_MAX = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    /**
     * Since 2.18.0 (Beacon spec 234, FR-034): the folder's shared status set — every board in it has these columns (as
     * {@see BoardColumnSpec::toArray()}), kept by {@see FolderStatusSets}; null: each board has its own columns.
     *
     * @var list<array{name: string, type: string, color: ?string, icon: ?string}>|null
     */
    #[ORM\Column(name: 'status_set', type: 'json', nullable: true)]
    private ?array $statusSet = null;

    public function __construct(
        string $name,
        #[ORM\Column(name: 'tenant_key', type: 'string', length: 191, nullable: true)]
        private ?string $tenantKey = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $description = null,
        #[ORM\Column(type: 'integer')]
        private int $position = 0,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->name      = Text::cut($name, self::NAME_MAX);
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = Text::cut($name, self::NAME_MAX);
        $this->touch();

        return $this;
    }

    /** Markdown, as a board's description with the Markdown format. */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $description;
        $this->touch();

        return $this;
    }

    /** The order among the tenant's folders (ascending; ties by name). */
    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->position = $position;
        $this->touch();

        return $this;
    }

    /**
     * Since 2.18.0 (FR-034): the shared status set, null when the boards keep their own columns.
     *
     * @return list<BoardColumnSpec>|null
     */
    public function getStatusSet(): ?array
    {
        return $this->statusSet === null ? null : array_map(BoardColumnSpec::fromArray(...), $this->statusSet);
    }

    /**
     * @param list<BoardColumnSpec>|null $statusSet
     *
     * @internal through {@see FolderStatusSets}, which applies it to every board of the folder
     */
    public function setStatusSet(?array $statusSet): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->statusSet = $statusSet === null ? null : array_values(array_map(static fn (BoardColumnSpec $spec): array => $spec->toArray(), $statusSet));
        $this->touch();

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

    private function touch(): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();
    }
}
