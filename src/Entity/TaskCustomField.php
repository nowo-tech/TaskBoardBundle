<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function count;
use function is_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * Since 2.19.0 (Beacon spec 234, FR-028): a native custom field — defined for a tenant (every board of it) or for one
 * board; text, number or dropdown (options with colours). Its values live on {@see TaskCustomFieldValue}, one per task.
 * Not to be confused with a provider's synced fields (`Sync\Entity\TaskSyncField`, read-only).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_custom_fields')]
#[ORM\Index(name: 'idx_task_board_custom_fields_tenant', columns: ['tenant_key'])]
#[ORM\UniqueConstraint(name: 'uniq_task_board_custom_fields_source', columns: ['board_id', 'source_ref'])]
class TaskCustomField
{
    public const NAME_MAX    = 128;
    public const OPTIONS_MAX = 200;

    /** Since 3.3.0 (CU-3): the longest provenance marker (`<provider>:<remote field id>`). */
    public const SOURCE_REF_MAX = 191;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 128)]
    private string $name;

    /** @var list<array{id: string, name: string, color: ?string}> */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    /**
     * Since 3.3.0 (CU-3, design §11): where an import created the field (`clickup:<field id>`), so a re-import finds it
     * again by id; null for a field defined here. Unique per board.
     */
    #[ORM\Column(name: 'source_ref', type: 'string', length: 191, nullable: true)]
    private ?string $sourceRef = null;

    /**
     * Since 3.3.0 (CU-3): the provider's dropdown option ids an import met, each with the option of this field it became
     * (renamed options stay the same option; an option deleted here is not recreated). Null until an import writes it.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(name: 'source_options', type: 'json', nullable: true)]
    private ?array $sourceOptions = null;

    /**
     * Since 3.3.0 (REQ-RUNTIME-003): the definition's revision, raised by every change of its name, position or options;
     * an edit made from an older revision is refused (compare-and-set under the row lock).
     */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    /**
     * @param TaskBoard|null $board null: a field of every board of the tenant
     */
    public function __construct(
        string $name,
        #[ORM\Column(name: 'field_type', type: 'string', length: 16, enumType: CustomFieldType::class)]
        private CustomFieldType $type,
        #[ORM\Column(name: 'tenant_key', type: 'string', length: 191, nullable: true)]
        private ?string $tenantKey = null,
        #[ORM\ManyToOne(targetEntity: TaskBoard::class)]
        #[ORM\JoinColumn(name: 'board_id', nullable: true, onDelete: 'CASCADE')]
        private ?TaskBoard $board = null,
        #[ORM\Column(type: 'integer')]
        private int $position = 0,
    ) {
        if ($board instanceof TaskBoard && $board->getTenantKey() !== $tenantKey) {
            throw new InvalidArgumentException('A board field belongs to the board\'s tenant.');
        }
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->setName($name);
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
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('A field name is 1 to ' . self::NAME_MAX . ' characters.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;

        return $this;
    }

    public function getType(): CustomFieldType
    {
        return $this->type;
    }

    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function getBoard(): ?TaskBoard
    {
        return $this->board;
    }

    public function getBoardId(): ?string
    {
        return $this->board?->getId();
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The dropdown's options, in order.
     *
     * @return list<array{id: string, name: string, color: ?string}>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** Since 3.3.0 (CU-3): the provenance marker of a field an import created, null otherwise. */
    public function getSourceRef(): ?string
    {
        return $this->sourceRef;
    }

    /**
     * Since 3.3.0 (CU-3): sets the provenance marker once (a field defined here, or one already marked, keeps its own).
     *
     * @internal through {@see TaskCustomFieldManager::defineFromSource()}
     */
    public function markSource(string $sourceRef): self
    {
        if (mb_strlen($sourceRef) > self::SOURCE_REF_MAX || preg_match('/^[a-z0-9_-]{1,32}:[A-Za-z0-9_-]{1,128}$/D', $sourceRef) !== 1) {
            throw new InvalidArgumentException('Invalid source reference.');
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->sourceRef ??= $sourceRef;

        return $this;
    }

    /**
     * Since 3.3.0 (CU-3): the provider option ids met so far, each with this field's option id.
     *
     * @return array<string, string>
     */
    public function getSourceOptions(): array
    {
        return $this->sourceOptions ?? [];
    }

    /**
     * Since 3.3.0 (CU-3): records that the provider's option `$remoteId` is this field's option `$optionId`.
     *
     * @internal through {@see TaskCustomFieldManager::adoptSourceOptions()}
     */
    public function mapSourceOption(string $remoteId, string $optionId): self
    {
        $map            = $this->sourceOptions ?? [];
        $map[$remoteId] = $optionId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->sourceOptions = $map;

        return $this;
    }

    /** Since 3.3.0 (REQ-RUNTIME-003): the definition's revision ({@see self::$version}). */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Since 3.3.0: called once per saved change of the definition, under its row lock. */
    public function bumpVersion(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        ++$this->version;

        return $this;
    }

    /**
     * Replaces the options. An option keeps its id when given one it already has; otherwise it gets a new id. A colour is a
     * hex colour (`#RGB` or `#RRGGBB`) or null.
     *
     * @param list<array{id?: ?string, name: string, color?: ?string}> $options
     *
     * @throws InvalidArgumentException
     */
    public function setOptions(array $options): self
    {
        self::assertOptions($this->type, $options);
        $known = [];
        foreach ($this->options as $option) {
            $known[$option['id']] = true;
        }
        $kept = [];
        foreach ($options as $option) {
            $id     = is_string($option['id'] ?? null) && isset($known[$option['id']]) ? $option['id'] : Uuid::generate()->toString();
            $kept[] = ['id' => $id, 'name' => Text::cut(trim($option['name']), self::NAME_MAX), 'color' => $option['color'] ?? null];
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->options = $kept;

        return $this;
    }

    /**
     * Since 2.19.0 (audit PW-M2): the checks of {@see self::setOptions()}, without a field (a caller checks its data before
     * it opens a transaction): only a dropdown has options, at most {@see self::OPTIONS_MAX}, each with a name (unique,
     * trimmed, not empty) and a hex colour or none.
     *
     * @param array<mixed> $options
     *
     * @throws InvalidArgumentException
     *
     * @phpstan-assert list<array{id?: ?string, name: string, color?: ?string}> $options
     */
    public static function assertOptions(CustomFieldType $type, array $options): void
    {
        if ($type !== CustomFieldType::Dropdown && $options !== []) {
            throw new InvalidArgumentException('Only a dropdown field has options.');
        }
        if (count($options) > self::OPTIONS_MAX) {
            throw new InvalidArgumentException('Too many options.');
        }
        $names = [];
        foreach ($options as $option) {
            if (!is_array($option) || !is_string($option['name'] ?? null)) {
                throw new InvalidArgumentException('An option needs a name.');
            }
            $name  = Text::cut(trim($option['name']), self::NAME_MAX);
            $color = $option['color'] ?? null;
            if ($name === '' || isset($names[$name]) || ($color !== null && (!is_string($color) || preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $color) !== 1))) {
                throw new InvalidArgumentException('Invalid option.');
            }
            $names[$name] = true;
        }
    }

    /** @return array{id: string, name: string, color: ?string}|null */
    public function option(string $id): ?array
    {
        foreach ($this->options as $option) {
            if ($option['id'] === $id) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The option of that exact name, if any.
     *
     * @return array{id: string, name: string, color: ?string}|null
     */
    public function optionNamed(string $name): ?array
    {
        foreach ($this->options as $option) {
            if ($option['name'] === $name) {
                return $option;
            }
        }

        return null;
    }
}
