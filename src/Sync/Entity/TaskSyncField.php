<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 1.27.0 (TB-SYNC-3): a provider custom field defined on a board (one row per remote field). Written by the
 * sync only; the values are {@see TaskSyncFieldValue}s.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_fields')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_fields_board_remote', columns: ['board_id', 'provider', 'remote_id'])]
class TaskSyncField
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'field_type', type: 'string', length: 16, enumType: SyncFieldType::class)]
    private SyncFieldType $type;

    #[ORM\Column(name: 'remote_type', type: 'string', length: 32)]
    private string $remoteType;

    /** @var list<array{id: string, name: string, color: string|null}> */
    #[ORM\Column(type: 'json')]
    private array $choices = [];

    /** @var array<string, int|string> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoard::class)]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoard $board,
        #[ORM\Column(type: 'string', length: 32)]
        private string $provider,
        #[ORM\Column(name: 'remote_id', type: 'string', length: 128)]
        private string $remoteId,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private DateTimeImmutable $updatedAt,
    ) {
        $this->id         = Uuid::generate()->toString();
        $this->name       = $remoteId;
        $this->type       = SyncFieldType::Text;
        $this->remoteType = '';
    }

    /**
     * Refreshes the definition; true when something changed.
     *
     * @param list<array{id: string, name: string, color: string|null}> $choices
     * @param array<string, int|string> $config
     */
    public function define(string $name, SyncFieldType $type, string $remoteType, array $choices, array $config, int $position, DateTimeImmutable $now): bool
    {
        if ($this->name === $name && $this->type === $type && $this->remoteType === $remoteType && $this->choices === $choices && $this->config === $config && $this->position === $position) {
            return false;
        }
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        [$this->name, $this->type, $this->remoteType, $this->choices, $this->config, $this->position, $this->updatedAt] = [$name, $type, $remoteType, $choices, $config, $position, $now];

        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): TaskBoard
    {
        return $this->board;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): SyncFieldType
    {
        return $this->type;
    }

    public function getRemoteType(): string
    {
        return $this->remoteType;
    }

    /**
     * @return list<array{id: string, name: string, color: string|null}>
     */
    public function getChoices(): array
    {
        return $this->choices;
    }

    /**
     * @return array<string, int|string>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** The name of a choice id (dropdown / labels), else the id. */
    public function choiceName(string $id): string
    {
        foreach ($this->choices as $choice) {
            if ($choice['id'] === $id) {
                return $choice['name'];
            }
        }

        return $id;
    }
}
