<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function is_array;
use function is_string;

/**
 * Since 1.23: the sync state of one remote task on a board — the hashes of the last synced value of each field (no
 * copy of the content). `task` null = a tombstone: the task was deleted here and is never recreated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_items')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_items_board_ext', columns: ['board_id', 'external_id'])]
#[ORM\Index(name: 'idx_task_board_sync_items_task', columns: ['task_id'])]
class TaskSyncItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    /**
     * @var array<string, array{0: string, 1: string}> field => [remote hash, local hash] last written
     */
    #[ORM\Column(name: 'field_hashes', type: 'json')]
    private array $fieldHashes = [];

    /** @var array<string, string> field => hash of the remote value already reported as a conflict */
    #[ORM\Column(name: 'conflict_hashes', type: 'json')]
    private array $conflictHashes = [];

    #[ORM\Column(name: 'remote_archived_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $remoteArchivedAt = null;

    #[ORM\Column(name: 'last_seen_run_id', type: 'string', length: 36, nullable: true)]
    private ?string $lastSeenRunId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoardConnection::class)]
        #[ORM\JoinColumn(name: 'connection_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoardConnection $connection,
        #[ORM\Column(name: 'board_id', type: 'string', length: 36)]
        private string $boardId,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'SET NULL')]
        private ?Task $task,
        #[ORM\Column(name: 'external_id', type: 'string', length: 128)]
        private string $externalId,
        #[ORM\Column(name: 'remote_updated_at', type: 'datetime_immutable')]
        private DateTimeImmutable $remoteUpdatedAt,
    ) {
        $this->id = Uuid::generate()->toString();
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

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->task = $task;

        return $this;
    }

    public function isTombstone(): bool
    {
        return !$this->task instanceof Task;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getRemoteUpdatedAt(): DateTimeImmutable
    {
        return $this->remoteUpdatedAt;
    }

    public function setRemoteUpdatedAt(DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->remoteUpdatedAt = $at;

        return $this;
    }

    /**
     * @return array{0: string, 1: string}|null [remote hash, local hash] of the last synced value
     */
    public function baseline(string $field): ?array
    {
        $pair = $this->fieldHashes[$field] ?? null;

        return is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null) ? [$pair[0], $pair[1]] : null;
    }

    public function setBaseline(string $field, string $remoteHash, string $localHash): self
    {
        $hashes         = $this->fieldHashes;
        $hashes[$field] = [$remoteHash, $localHash];
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->fieldHashes = $hashes;

        return $this;
    }

    public function conflictHash(string $field): ?string
    {
        $hash = $this->conflictHashes[$field] ?? null;

        return is_string($hash) ? $hash : null;
    }

    public function setConflictHash(string $field, ?string $hash): self
    {
        $hashes = $this->conflictHashes;
        if ($hash === null) {
            unset($hashes[$field]);
        } else {
            $hashes[$field] = $hash;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->conflictHashes = $hashes;

        return $this;
    }

    public function getRemoteArchivedAt(): ?DateTimeImmutable
    {
        return $this->remoteArchivedAt;
    }

    public function setRemoteArchivedAt(?DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->remoteArchivedAt = $at;

        return $this;
    }

    public function getLastSeenRunId(): ?string
    {
        return $this->lastSeenRunId;
    }

    public function setLastSeenRunId(string $runId): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastSeenRunId = $runId;

        return $this;
    }
}
