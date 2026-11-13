<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_values;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061, `task_templates.enabled`): a task template. It belongs to one board (`board`
 * set, `tenantKey` null) or to a tenant (`board` null; `tenantKey` the tenant's key, or null for the boards without a
 * tenant). A template pre-fills a new task: the Markdown description body, the default tags and, for a board template,
 * the default column (a column id of that board; a column deleted later is ignored, never followed to another board).
 *
 * Gone with its board (`ON DELETE CASCADE`). Holds no user: a host keeps its own audit.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_task_board_task_templates_tenant', columns: ['tenant_key'])]
class TaskTemplate
{
    public const TABLE = 'task_board_task_templates';

    /** The longest name. */
    public const NAME_MAX = 120;

    /** The longest description body, in characters. */
    public const BODY_MAX = 65535;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $defaultTags
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoard::class)]
        #[ORM\JoinColumn(name: 'board_id', nullable: true, onDelete: 'CASCADE')]
        private ?TaskBoard $board,
        #[ORM\Column(name: 'tenant_key', type: 'string', length: 191, nullable: true)]
        private ?string $tenantKey,
        #[ORM\Column(type: 'string', length: 120)]
        private string $name,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $body = null,
        #[ORM\Column(name: 'default_tags', type: 'json')]
        private array $defaultTags = [],
        #[ORM\Column(name: 'default_column_id', type: 'string', length: 36, nullable: true)]
        private ?string $defaultColumnId = null,
        ?DateTimeImmutable $now = null,
    ) {
        $this->id          = Uuid::generate()->toString();
        $this->createdAt   = $now ?? new DateTimeImmutable();
        $this->updatedAt   = $this->createdAt;
        $this->defaultTags = array_values($defaultTags);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** The board of a board template; null for a tenant template. */
    public function getBoard(): ?TaskBoard
    {
        return $this->board;
    }

    /** The tenant key of a tenant template (null: the boards without a tenant); always null for a board template. */
    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function isBoardTemplate(): bool
    {
        return $this->board instanceof TaskBoard;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** The Markdown description body, or null. */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getDefaultTags(): array
    {
        return array_values($this->defaultTags);
    }

    /** A column id of the template's board, or null. */
    public function getDefaultColumnId(): ?string
    {
        return $this->defaultColumnId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param list<string> $defaultTags
     */
    public function change(string $name, ?string $body, array $defaultTags, ?string $defaultColumnId, DateTimeImmutable $now): void
    {
        $this->name            = $name;
        $this->body            = $body;
        $this->defaultTags     = array_values($defaultTags);
        $this->defaultColumnId = $defaultColumnId;
        $this->updatedAt       = $now;
    }
}
