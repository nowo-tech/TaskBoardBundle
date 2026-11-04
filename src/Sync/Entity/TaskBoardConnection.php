<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_values;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Since 1.23: the link of a board to one remote list (one per board). It holds the host's opaque connection
 * reference — never a token.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_connections')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_connections_board', columns: ['board_id'])]
class TaskBoardConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_run_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'last_success_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastSuccessAt = null;

    /** The highest remote update time written by the last successful run. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $watermark = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'sync_cursor', type: 'json', nullable: true)]
    private ?array $cursor = null;

    /** Written by {@see SyncStore}'s lock queries. */
    #[ORM\Column(name: 'running_run_id', type: 'string', length: 36, nullable: true)]
    private ?string $runningRunId = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'heartbeat_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $heartbeatAt = null; // @phpstan-ignore property.unusedType

    /**
     * @param list<string> $containerPath
     * @param array<string, bool|string|null> $options
     */
    public function __construct(
        #[ORM\OneToOne(targetEntity: TaskBoard::class)]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoard $board,
        #[ORM\Column(type: 'string', length: 32)]
        private string $provider,
        #[ORM\Column(name: 'import_scope', type: 'string', length: 64)]
        private string $importScope,
        #[ORM\Column(name: 'connection_ref', type: 'string', length: 191)]
        private string $connectionRef,
        #[ORM\Column(name: 'container_id', type: 'string', length: 128)]
        private string $containerId,
        #[ORM\Column(name: 'container_label', type: 'string', length: 255)]
        private string $containerLabel,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $createdBy = null,
        #[ORM\Column(name: 'container_path', type: 'json')]
        private array $containerPath = [],
        #[ORM\Column(type: 'json')]
        private array $options = [],
    ) {
        $this->id             = Uuid::generate()->toString();
        $this->createdAt      = new DateTimeImmutable();
        $this->containerLabel = Text::cut($containerLabel, 255);
        $this->containerPath  = array_values($containerPath);
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

    public function getImportScope(): string
    {
        return $this->importScope;
    }

    public function getConnectionRef(): string
    {
        return $this->connectionRef;
    }

    public function getContainerId(): string
    {
        return $this->containerId;
    }

    public function getContainerLabel(): string
    {
        return $this->containerLabel;
    }

    /**
     * @return list<string>
     */
    public function getContainerPath(): array
    {
        return $this->containerPath;
    }

    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastRunAt(): ?DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastSuccessAt(): ?DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getWatermark(): ?DateTimeImmutable
    {
        return $this->watermark;
    }

    public function getRunningRunId(): ?string
    {
        return $this->runningRunId;
    }

    public function getHeartbeatAt(): ?DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    public function option(string $name, bool $default): bool
    {
        $value = $this->options[$name] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function fallbackColumnId(): ?string
    {
        $value = $this->options['fallbackColumnId'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, bool|string|null> $options
     */
    public function setOptions(array $options): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->options = $options;

        return $this;
    }

    public function getCursor(): ?SyncCursor
    {
        return is_array($this->cursor) ? SyncCursor::fromArray($this->cursor) : null;
    }

    public function setCursor(?SyncCursor $cursor): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->cursor = $cursor?->toArray();

        return $this;
    }

    public function markRun(DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastRunAt = $at;

        return $this;
    }

    public function markSuccess(DateTimeImmutable $at, ?DateTimeImmutable $watermark): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastSuccessAt = $at;
        if ($watermark instanceof DateTimeImmutable && (!$this->watermark instanceof DateTimeImmutable || $watermark > $this->watermark)) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->watermark = $watermark;
        }

        return $this;
    }
}
