<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 1.23: one run of the history (its slices update the same row). The report is
 * {@see SyncReport::toArray()}: counters, conflicts, codes — no secret.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_runs')]
#[ORM\Index(name: 'idx_task_board_sync_runs_board', columns: ['board_id', 'started_at'])]
class TaskSyncRun
{
    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'heartbeat_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $heartbeatAt = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'running';

    #[ORM\Column(name: 'cancel_requested', type: 'boolean')]
    private bool $cancelRequested = false;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $report = [];

    /** The start in microseconds: orders the runs of one second (started_at has whole seconds). */
    #[ORM\Column(name: 'run_order', type: 'bigint')]
    private int|string $runOrder; // @phpstan-ignore property.unusedType (Doctrine may hydrate a bigint as a string)

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private string $id,
        #[ORM\ManyToOne(targetEntity: TaskBoardConnection::class)]
        #[ORM\JoinColumn(name: 'connection_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoardConnection $connection,
        #[ORM\Column(name: 'board_id', type: 'string', length: 36)]
        private string $boardId,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $actor,
        #[ORM\Column(name: 'run_trigger', type: 'string', length: 16)]
        private string $trigger,
        #[ORM\Column(type: 'string', length: 16)]
        private string $mode,
        #[ORM\Column(name: 'dry_run', type: 'boolean')]
        private bool $dryRun,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
        private DateTimeImmutable $startedAt,
    ) {
        $this->runOrder = (int) round(microtime(true) * 1_000_000);
    }

    public function getRunOrder(): int
    {
        return (int) $this->runOrder;
    }

    public static function newId(): string
    {
        return Uuid::generate()->toString();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getConnection(): TaskBoardConnection
    {
        return $this->connection;
    }

    public function getBoardId(): string
    {
        return $this->boardId;
    }

    public function getActor(): ?object
    {
        return $this->actor;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getHeartbeatAt(): ?DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    public function getStatus(): SyncStatus
    {
        return SyncStatus::tryFrom($this->status) ?? SyncStatus::Failed;
    }

    public function isCancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    public function requestCancel(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->cancelRequested = true;

        return $this;
    }

    public function getReport(): ?SyncReport
    {
        return $this->report === [] ? null : SyncReport::fromArray($this->report);
    }

    public function record(SyncReport $report, DateTimeImmutable $now): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->report = $report->toArray();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = $report->status->value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->heartbeatAt = $now;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mode = $report->mode->value;
        if ($report->status->isTerminal()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->finishedAt = $now;
        }

        return $this;
    }
}
