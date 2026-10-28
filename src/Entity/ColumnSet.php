<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_values;
use function is_string;

/**
 * A named, reusable list of board columns (since 1.11): saved from a board and applied to a new board or appended to
 * an existing one (each board gets copies, so editing a board never changes the set).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_column_sets')]
#[ORM\Index(name: 'idx_task_board_column_sets_scope', columns: ['scope_key'])]
class ColumnSet
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var list<array{name: string, color: ?string, icon: ?string}> */
    #[ORM\Column(type: 'json')]
    private array $items = [];

    /**
     * @param string|null $scopeKey Host scope of the set (e.g. a tenant key); null: shared by every board
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 128)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $createdBy = null,
        #[ORM\Column(name: 'scope_key', type: 'string', length: 191, nullable: true)]
        private ?string $scopeKey = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * A set holding copies of `$board`'s columns, in board order.
     */
    public static function fromBoard(TaskBoard $board, string $name, ?object $createdBy = null, ?string $scopeKey = null): self
    {
        $set = new self($name, $createdBy, $scopeKey);
        foreach ($board->getColumns() as $column) {
            $set->addItem($column->getName(), $column->getColor(), $column->getIcon());
        }

        return $set;
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
        $this->name = $name;

        return $this;
    }

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The columns of the set, in order.
     *
     * @return list<array{name: string, color: ?string, icon: ?string}>
     */
    public function getItems(): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[] = [
                'name'  => $item['name'],
                'color' => is_string($item['color'] ?? null) ? $item['color'] : null,
                'icon'  => is_string($item['icon'] ?? null) ? $item['icon'] : null,
            ];
        }

        return $items;
    }

    public function addItem(string $name, ?string $color = null, ?string $icon = null): self
    {
        $items   = array_values($this->items);
        $items[] = ['name' => $name, 'color' => $color, 'icon' => $icon];
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->items = $items;

        return $this;
    }
}
