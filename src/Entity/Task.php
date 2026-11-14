<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use SortDirection;

use function max;
use function strtolower;

#[ORM\Entity]
#[ORM\Table(name: 'task_board_tasks')]
#[ORM\Index(name: 'task_board_tasks_board_idx', columns: ['board_id'])]
#[ORM\Index(name: 'task_board_tasks_board_archived_idx', columns: ['board_id', 'archived_at'])]
// Since 1.25 (KIT-74): open tasks of a board by due date (due-date alerts and reminders).
#[ORM\Index(name: 'task_board_tasks_board_open_due_idx', columns: ['board_id', 'completed_at', 'due_at'])]
// Since 2.1.0 (spec 214): the tasks a backfill still has to number.
#[ORM\Index(name: 'task_board_tasks_number_idx', columns: ['number'])]
#[ORM\HasLifecycleCallbacks]
class Task
{
    /** Since 1.16: the longest title (column length); longer ones are cut. */
    public const TITLE_MAX = 255;

    /** Since 1.23: archived by a person (a provider sync never restores it). */
    public const ARCHIVE_REASON_USER = 'user';

    /** Since 1.23: archived by a provider sync because the remote task is gone (restored when it comes back). */
    public const ARCHIVE_REASON_REMOTE = 'remote';

    /** Since 1.25: the longest creator / author name snapshot (column length). */
    public const USER_NAME_MAX = 180;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    /** Since 1.25 (audit FP-M1): the creator's name, kept when the account is erased (`creator_id` SET NULL). */
    #[ORM\Column(name: 'creator_name', type: 'string', length: 180, nullable: true)]
    private ?string $creatorName;

    /**
     * Since 2.18.0 (Beacon spec 234): who made the task's last recorded write (the user of its newest history row; null
     * for rows written before 2.18, or once the account was erased). With {@see self::$channel} and
     * {@see self::$actorLabel} it is the provenance of that write; the history keeps each one.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $updatedBy = null;

    /** Since 2.18.0 (Beacon spec 234): where the last recorded write came from; null: the host did not say. */
    // Audit PS-I2: a plain string, read through WriteChannel::tryFrom(); a value outside the list reads as null.
    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $channel = null;

    /** Since 2.18.0 (Beacon spec 234): the host's plain-text label of the last write's actor (at most 120 characters). */
    #[ORM\Column(name: 'actor_label', type: 'string', length: 120, nullable: true)]
    private ?string $actorLabel = null;

    #[ORM\Column(name: 'total_time_seconds', type: 'integer')]
    private int $totalTimeSeconds = 0;

    /** Since 1.15: the day work starts — the Gantt bar starts here instead of the creation day (null = createdAt). */
    #[ORM\Column(name: 'start_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startAt = null;

    /** Since 1.23: when the task was archived (hidden from the board, the list and the Gantt); null = active. */
    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    /** Since 1.23: {@see self::ARCHIVE_REASON_USER} or {@see self::ARCHIVE_REASON_REMOTE}. */
    #[ORM\Column(name: 'archive_reason', type: 'string', length: 16, nullable: true)]
    private ?string $archiveReason = null;

    /** Since 1.14: the task's own colour (#rrggbb), e.g. its Gantt bar; null keeps the priority colours. */
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * Since 2.1.0 (spec 214, `task_numbers.enabled`): the task's readable number (`#123`) in its scope; null with the option
     * off, or until a backfill reaches an older task. Assigned at persistence; the ledger is what makes it unique.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $number = null;

    /**
     * Since 2.16.0 (spec 234, FR-018): +1 on every flush that changes the task (any field but its position, its total time
     * and `updatedAt`) or its assignees and watchers; 1 for a new task. The Task API's optimistic lock.
     *
     * Since 2.17.0 (audit PC-L4): never written from PHP (`updatable: false`); the database adds the 1
     * (`revision = revision + 1`) inside the flush's transaction, and the value is read back here.
     */
    #[ORM\Column(type: 'integer', updatable: false, options: ['default' => 1])]
    private int $revision = 1;

    /**
     * Since 2.17.0 (audit PC-L4): set by the flush listener when this flush changes the task, cleared once the database
     * added the 1. Not mapped; lives as long as this object (never in a service).
     */
    private bool $revisionPending = false;

    /** @var Collection<int, TaskChangeHistory> */
    #[ORM\OneToMany(targetEntity: TaskChangeHistory::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => SortDirection::Ascending])]
    private Collection $changeHistory;

    /** @var Collection<int, TaskMember> */
    #[ORM\OneToMany(targetEntity: TaskMember::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, TaskLink> */
    #[ORM\OneToMany(targetEntity: TaskLink::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $links;

    /** @var Collection<int, TaskDependency> */
    #[ORM\OneToMany(targetEntity: TaskDependency::class, mappedBy: 'sourceTask', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $outgoingDependencies;

    /** @var Collection<int, TaskDependency> */
    #[ORM\OneToMany(targetEntity: TaskDependency::class, mappedBy: 'targetTask', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $incomingDependencies;

    /** @var Collection<int, Task> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    /** @var Collection<int, TaskDocument> */
    #[ORM\OneToMany(targetEntity: TaskDocument::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * Since 1.23.2 no `remove` cascade and no orphan removal: deleting the task never deletes tracked time — the
     * entries are detached ({@see self::detachTimeEntries()}, `ON DELETE SET NULL`) and keep their snapshots.
     *
     * @var Collection<int, TaskTimeEntry>
     */
    #[ORM\OneToMany(targetEntity: TaskTimeEntry::class, mappedBy: 'task', cascade: ['persist'])]
    private Collection $timeEntries;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoard::class, inversedBy: 'tasks')]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoard $board,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        // Since 1.25 (audit FP-M1): nullable, ON DELETE SET NULL; the name stays in `creator_name`.
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?object $creator,
        #[ORM\ManyToOne(targetEntity: BoardColumn::class, inversedBy: 'tasks')]
        #[ORM\JoinColumn(name: 'column_id', nullable: true, onDelete: 'SET NULL')]
        private ?BoardColumn $column = null,
        #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
        #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'CASCADE')]
        private ?self $parent = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $description = null,
        #[ORM\Column(type: 'string', length: 16, enumType: TaskPriority::class)]
        private TaskPriority $priority = TaskPriority::Normal,
        #[ORM\Column(type: 'integer')]
        private int $position = 0,
        #[ORM\Column(name: 'estimated_minutes', type: 'integer', nullable: true)]
        private ?int $estimatedMinutes = null,
        #[ORM\Column(name: 'due_at', type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $dueAt = null,
        /** @var list<string> */
        #[ORM\Column(type: 'json')]
        private array $tags = [],
        // Since 1.25: the creation date of the source tool (a provider sync); null = now.
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::generate()->toString();
        // Since 1.16: cut to the column (VARCHAR(255)) whatever the caller (imports, host integrations).
        $this->title                = Text::cut($title, self::TITLE_MAX);
        $this->createdAt            = $createdAt ?? new DateTimeImmutable();
        $this->updatedAt            = new DateTimeImmutable();
        $this->tags                 = $this->normalizeTags($tags);
        $this->changeHistory        = new ArrayCollection();
        $this->members              = new ArrayCollection();
        $this->links                = new ArrayCollection();
        $this->outgoingDependencies = new ArrayCollection();
        $this->incomingDependencies = new ArrayCollection();
        $this->children             = new ArrayCollection();
        $this->documents            = new ArrayCollection();
        $this->timeEntries          = new ArrayCollection();
        $this->creatorName          = $creator === null ? null : Text::cut(UserLabel::of($creator), self::USER_NAME_MAX);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): TaskBoard
    {
        return $this->board;
    }

    /**
     * Since 2.3.0 (spec 217): puts the task on another board, in `$column` of that board. Only this task: the mover walks
     * the subtree. The time entries keep their rows and take the new board as their snapshot.
     *
     * @internal used by {@see TaskMover} only
     */
    public function changeBoard(TaskBoard $board, ?BoardColumn $column, int $position): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->board = $board;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->column = $column;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->position = $position;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();
        $this->refreshTimeEntrySnapshots();

        return $this;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    /**
     * For the number listener only: no host code assigns a number.
     */
    public function assignNumber(int $number): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->number = $number;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $title   = Text::cut($title, self::TITLE_MAX);
        $renamed = $title !== $this->title;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->title = $title;
        if ($renamed) {
            // Since 1.23.2: the time entries keep the current title as their snapshot.
            $this->refreshTimeEntrySnapshots();
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** The creator; null once the account was erased (since 1.25, see {@see self::getCreatorName()}). */
    public function getCreator(): ?object
    {
        return $this->creator;
    }

    /** Since 1.25: the creator's name when the task was created or last re-assigned (kept when the account goes). */
    public function getCreatorName(): ?string
    {
        return $this->creatorName;
    }

    /**
     * Since 1.25 (audit FP-M1): the account is being erased — the task keeps its name snapshot (null with
     * `$forgetName`) and no longer points at the user, so deleting the user deletes nothing of the board.
     *
     * @internal used by the account erasure helper ({@see TaskSyncPeopleReassigner::detachUser()})
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

    /**
     * Since 2.18.0 (Beacon spec 234): who made the last recorded write and its provenance (null: none, the columns are
     * cleared). Changing these fields alone is no new revision.
     *
     * @internal called by {@see TaskChangeRecorder} at every history row
     */
    public function recordWrite(object $actor, ?WriteProvenance $provenance): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedBy = $actor;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->channel = $provenance?->channel->value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->actorLabel = WriteProvenance::normalizeLabel($provenance?->actorLabel);

        return $this;
    }

    /** Since 2.18.0 (Beacon spec 234): who made the last recorded write; null before 2.18 or once the account was erased. */
    public function getUpdatedBy(): ?object
    {
        return $this->updatedBy;
    }

    /** Since 2.18.0 (Beacon spec 234): where the last recorded write came from. */
    public function getChannel(): ?WriteChannel
    {
        return $this->channel === null ? null : WriteChannel::tryFrom($this->channel);
    }

    /** Since 2.18.0 (Beacon spec 234): the host's plain-text label of the last write's actor. */
    public function getActorLabel(): ?string
    {
        return $this->actorLabel;
    }

    /**
     * Since 2.18.0: the account of the last writer is being erased — the task stops pointing at it; with `$forgetName` the
     * actor label goes too.
     *
     * @internal used by the account erasure helper ({@see TaskSyncPeopleReassigner::detachUser()})
     */
    public function detachUpdatedBy(bool $forgetName = false): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedBy = null;
        if ($forgetName) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->actorLabel = null;
        }

        return $this;
    }

    public function getColumn(): ?BoardColumn
    {
        return $this->column;
    }

    public function setColumn(?BoardColumn $column): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->column = $column;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Since 1.22: moves the task under another task of the same board (null: to the root). The caller keeps the tree
     * acyclic (an import refuses a parent that is the task itself or one of its descendants).
     */
    public function setParent(?self $parent): self
    {
        if ($this->parent === $parent) {
            return $this;
        }
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->parent?->children->removeElement($this);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->parent = $parent;
        if ($parent instanceof self && !$parent->children->contains($this)) {
            $parent->children->add($this);
        }
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

    public function getPriority(): TaskPriority
    {
        return $this->priority;
    }

    public function setPriority(TaskPriority $priority): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->priority = $priority;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->position = $position;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $estimatedMinutes): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->estimatedMinutes = $estimatedMinutes;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getDueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?DateTimeImmutable $dueAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->dueAt = $dueAt;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getStartAt(): ?DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(?DateTimeImmutable $startAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->startAt = $startAt;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** Since 1.15: the start of the task's plan: its start date, else its creation. */
    public function getPlannedStart(): DateTimeImmutable
    {
        return $this->startAt ?? $this->createdAt;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->color = $color !== null && $color !== '' ? strtolower($color) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->tags = $this->normalizeTags($tags);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Since 1.25: the source tool's creation date, once, for a task a 1.23 sync created (back-fill).
     *
     * @internal used by the provider sync only
     */
    public function importCreatedAt(DateTimeImmutable $createdAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Since 1.25: a new creator after a people re-association or back-fill (no history, no notification).
     *
     * @internal used by the provider sync's people services only
     */
    public function reassignCreator(object $creator): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creator = $creator;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creatorName = Text::cut(UserLabel::of($creator), self::USER_NAME_MAX);

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

    public function isCompleted(): bool
    {
        return $this->completedAt instanceof DateTimeImmutable;
    }

    /** Since 1.27.0: `$at` = when it was done (a provider's done date); default now. */
    public function markCompleted(?DateTimeImmutable $at = null): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->completedAt = $at ?? new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function markIncomplete(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->completedAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Since 1.23: hides the task from the board, the list and the Gantt (nothing is deleted). An archived task keeps
     * its first archive reason.
     */
    public function archive(string $reason = self::ARCHIVE_REASON_USER): self
    {
        if ($this->archivedAt instanceof DateTimeImmutable) {
            return $this;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->archivedAt = new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->archiveReason = $reason === self::ARCHIVE_REASON_REMOTE ? self::ARCHIVE_REASON_REMOTE : self::ARCHIVE_REASON_USER;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** Since 1.23: makes an archived task active again. */
    public function restore(): self
    {
        if (!$this->archivedAt instanceof DateTimeImmutable) {
            return $this;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->archivedAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->archiveReason = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof DateTimeImmutable;
    }

    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

    /** @return Collection<int, Task> */
    public function getSubtasks(): Collection
    {
        return $this->children;
    }

    public function getTotalTimeSeconds(): int
    {
        return $this->totalTimeSeconds;
    }

    /**
     * Whole minutes logged on the task: time aggregated from TimeTrack timers (total_time_seconds,
     * 0 when TimeTrackBundle is not installed) plus manual TaskTimeEntry rows.
     */
    public function getLoggedMinutes(): int
    {
        $minutes = intdiv($this->totalTimeSeconds, 60);
        foreach ($this->timeEntries as $entry) {
            $minutes += $entry->getMinutes();
        }

        return $minutes;
    }

    public function addTimeSeconds(int $seconds): self
    {
        if ($seconds > 0) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->totalTimeSeconds += $seconds;
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->updatedAt = new DateTimeImmutable();
        }

        return $this;
    }

    /**
     * Since 1.25: removes aggregated TimeTrack seconds (imported entries deleted in TimeTrack); the total never goes
     * below 0. Values ≤ 0 are ignored.
     *
     * @internal used by the TimeTrack bridge
     */
    public function subtractTimeSeconds(int $seconds): self
    {
        if ($seconds > 0) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->totalTimeSeconds = max(0, $this->totalTimeSeconds - $seconds);
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->updatedAt = new DateTimeImmutable();
        }

        return $this;
    }

    public function getAssignee(): ?object
    {
        foreach ($this->members as $member) {
            if ($member->getMemberRole() === TaskMemberRole::Assignee) {
                return $member->getUser();
            }
        }

        return null;
    }

    /** @return Collection<int, TaskChangeHistory> */
    public function getChangeHistory(): Collection
    {
        return $this->changeHistory;
    }

    public function addChangeHistory(TaskChangeHistory $entry): self
    {
        if (!$this->changeHistory->contains($entry)) {
            $this->changeHistory->add($entry);
        }

        return $this;
    }

    /** @return Collection<int, TaskMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(TaskMember $member): self
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setTask($this);
        }

        return $this;
    }

    public function removeMember(TaskMember $member): self
    {
        $this->members->removeElement($member);

        return $this;
    }

    /** @return Collection<int, TaskLink> */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function addLink(TaskLink $link): self
    {
        if (!$this->links->contains($link)) {
            $this->links->add($link);
            $link->setTask($this);
        }

        return $this;
    }

    public function removeLink(TaskLink $link): self
    {
        $this->links->removeElement($link);

        return $this;
    }

    /** @return Collection<int, TaskDependency> */
    public function getOutgoingDependencies(): Collection
    {
        return $this->outgoingDependencies;
    }

    /** @return Collection<int, TaskDependency> */
    public function getIncomingDependencies(): Collection
    {
        return $this->incomingDependencies;
    }

    /**
     * Since 1.15: links a dependency to both of its tasks (this task must be its source).
     */
    public function addOutgoingDependency(TaskDependency $dependency): self
    {
        if (!$this->outgoingDependencies->contains($dependency)) {
            $this->outgoingDependencies->add($dependency);
        }

        $incoming = $dependency->getTargetTask()->incomingDependencies;
        if (!$incoming->contains($dependency)) {
            $incoming->add($dependency);
        }

        return $this;
    }

    /**
     * Since 1.15: unlinks a dependency from both of its tasks (orphan removal deletes it).
     */
    public function removeDependency(TaskDependency $dependency): self
    {
        $dependency->getSourceTask()->outgoingDependencies->removeElement($dependency);
        $dependency->getTargetTask()->incomingDependencies->removeElement($dependency);

        return $this;
    }

    /** @return Collection<int, TaskDocument> */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    /** @return Collection<int, TaskTimeEntry> */
    public function getTimeEntries(): Collection
    {
        return $this->timeEntries;
    }

    /** Since 1.15: a manual time entry (logged from the task detail). */
    public function addTimeEntry(TaskTimeEntry $entry): self
    {
        if (!$this->timeEntries->contains($entry)) {
            $this->timeEntries->add($entry);
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->updatedAt = new DateTimeImmutable();
        }

        return $this;
    }

    /**
     * Since 1.15. Since 1.23.2 only drops the entry from the collection: the caller deletes it explicitly
     * ({@see TaskTimeLogger::remove()}); there is no orphan removal any more.
     */
    public function removeTimeEntry(TaskTimeEntry $entry): self
    {
        $this->timeEntries->removeElement($entry);

        return $this;
    }

    /**
     * Since 1.23.2: the external id of an imported / synced task — the first link an import or a provider sync wrote
     * (`importSource` + `externalId`); null for a native task.
     */
    public function getExternalId(): ?string
    {
        foreach ($this->links as $link) {
            if ($link->getImportSource() !== null && $link->getExternalId() !== null) {
                return $link->getExternalId();
            }
        }

        return null;
    }

    /** Since 1.23.2: copies the title, board id and external id of the task into each of its time entries. */
    public function refreshTimeEntrySnapshots(): self
    {
        foreach ($this->timeEntries as $entry) {
            $entry->refreshTaskSnapshot();
        }

        return $this;
    }

    /**
     * Since 1.23.2: before the task is removed (directly, or by the board's `remove` cascade) its time entries are
     * detached with fresh snapshots instead of being deleted. Deletes that bypass the ORM (DQL / SQL, the database
     * cascades of a parent task or a board) rely on `ON DELETE SET NULL` and the snapshots kept since creation.
     */
    #[ORM\PreRemove]
    public function detachTimeEntries(): void
    {
        foreach ($this->timeEntries as $entry) {
            $entry->detachFromTask();
        }
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        /** @var list<string> $normalized */
        $normalized = [];
        $seen       = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && !isset($seen[$tag])) {
                $seen[$tag]   = true;
                $normalized[] = $tag;
            }
        }

        sort($normalized);

        return $normalized;
    }

    /** Since 2.16.0 (spec 234, FR-018): the optimistic-lock revision; see {@see self::$revision}. */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Since 2.17.0 (audit PC-L4): this flush changes the task; called by the bundle's flush listener only.
     *
     * @internal
     */
    public function markRevisionPending(): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->revisionPending = true;
    }

    /**
     * Since 2.17.0 (audit PC-L4): whether the 1 of this flush is still to add, clearing it; the bundle's flush listener only.
     *
     * @internal
     */
    public function takeRevisionPending(): bool
    {
        $pending = $this->revisionPending;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->revisionPending = false;

        return $pending;
    }
}
