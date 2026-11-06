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
    /** Since 1.27.0: the files could not be read (request budget, or the detail does not say). */
    public const PENDING_ATTACHMENTS = 1;

    /** Since 1.27.0: threads past the run's reply budget. */
    public const PENDING_REPLIES = 2;

    /** Since 1.27.0: the detail was not read (request budget). */
    public const PENDING_DETAIL = 4;

    /** Since 1.27.0: the provider refuses the detail with a lasting error. */
    public const PENDING_DETAIL_REFUSED = 8;

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

    /** Since 1.25: the remote creator (null = unknown). */
    #[ORM\ManyToOne(targetEntity: TaskSyncPerson::class)]
    #[ORM\JoinColumn(name: 'creator_person_id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskSyncPerson $creatorPerson = null;

    /**
     * Since 1.25: remote assignees / watchers not written as members (unmatched, external or without board access).
     *
     * @var array<string, list<string>>|null role => person ids
     */
    #[ORM\Column(name: 'unmatched_people', type: 'json', nullable: true)]
    private ?array $unmatchedPeople = null;

    /**
     * Since 1.27.0 (TB-SYNC-3): what the provider has besides the import row (custom id, points, done / closed dates,
     * time flags, location path, estimates per assignee, recurrence, status history), read-only on the task.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'remote_meta', type: 'json', nullable: true)]
    private ?array $remoteMeta = null;

    /** Since 1.27.0 (audit GWd-L3): what this task still has unread, `PENDING_*` bits; the report reads it. */
    #[ORM\Column(name: 'pending_reads', type: 'smallint', options: ['default' => 0])]
    private int $pendingReads = 0;

    /** Since 1.27.0 (TB-SYNC-3): the provider's own task reference (e.g. "DEV-123"); a column so that the list never reads `remote_meta`. */
    #[ORM\Column(name: 'custom_id', type: 'string', length: 64, nullable: true)]
    private ?string $customId = null;

    /** Since 1.25: 0 = written by 1.23 (creator and creation date are back-filled once), 1 = current. */
    #[ORM\Column(name: 'backfill_level', type: 'smallint', options: ['default' => 0])]
    private int $backfillLevel = 1;

    /** Since 1.25: `task` or `checklist_item` (a subtask made from a checklist item, external id "<task>#<item>"). */
    #[ORM\Column(name: 'item_kind', type: 'string', length: 16, options: ['default' => 'task'])]
    private string $itemKind = self::KIND_TASK;

    public const KIND_TASK = 'task';

    public const KIND_CHECKLIST_ITEM = 'checklist_item';

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

    public function getCreatorPerson(): ?TaskSyncPerson
    {
        return $this->creatorPerson;
    }

    public function setCreatorPerson(?TaskSyncPerson $person): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creatorPerson = $person;

        return $this;
    }

    /**
     * Since 1.27.0.
     *
     * @return array<string, mixed>
     */
    public function getRemoteMeta(): array
    {
        return $this->remoteMeta ?? [];
    }

    public function hasPendingRead(int $bit): bool
    {
        return ($this->pendingReads & $bit) !== 0;
    }

    /** Since 1.27.0: marks one `PENDING_*` bit. */
    public function setPendingRead(int $bit, bool $pending): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->pendingReads = $pending ? $this->pendingReads | $bit : $this->pendingReads & ~$bit;
    }

    public function getCustomId(): ?string
    {
        return $this->customId;
    }

    /** Since 1.27.0: true when it changed. */
    public function setCustomId(?string $customId): bool
    {
        if ($customId === $this->customId) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->customId = $customId;

        return true;
    }

    /**
     * Since 1.27.0: true when it changed (MySQL's JSON type reorders keys: the order does not count as a change).
     *
     * @param array<string, mixed> $meta
     */
    public function setRemoteMeta(array $meta): bool
    {
        $meta = $meta === [] ? null : self::canonical($meta);
        if ($meta === ($this->remoteMeta === null ? null : self::canonical($this->remoteMeta))) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->remoteMeta = $meta;

        return true;
    }

    /**
     * @return list<string> person ids of a role ("assignee" / "watcher") not written as members
     */
    public function unmatchedPeople(string $role): array
    {
        return $this->unmatchedPeople[$role] ?? [];
    }

    /**
     * @param list<string> $personIds
     */
    public function setUnmatchedPeople(string $role, array $personIds): self
    {
        $people = $this->unmatchedPeople ?? [];
        if ($personIds === []) {
            unset($people[$role]);
        } else {
            $people[$role] = $personIds;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->unmatchedPeople = $people === [] ? null : $people;

        return $this;
    }

    public function getBackfillLevel(): int
    {
        return $this->backfillLevel;
    }

    public function setBackfillLevel(int $level): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->backfillLevel = $level;

        return $this;
    }

    public function getItemKind(): string
    {
        return $this->itemKind;
    }

    public function setItemKind(string $kind): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->itemKind = $kind;

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

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonical($child);
            }
        }

        return $value;
    }
}
