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
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use SortDirection;

use function strtolower;

#[ORM\Entity]
#[ORM\Table(name: 'task_board_tasks')]
#[ORM\Index(name: 'task_board_tasks_board_idx', columns: ['board_id'])]
#[ORM\Index(name: 'task_board_tasks_board_archived_idx', columns: ['board_id', 'archived_at'])]
class Task
{
    /** Since 1.16: the longest title (column length); longer ones are cut. */
    public const TITLE_MAX = 255;

    /** Since 1.23: archived by a person (a provider sync never restores it). */
    public const ARCHIVE_REASON_USER = 'user';

    /** Since 1.23: archived by a provider sync because the remote task is gone (restored when it comes back). */
    public const ARCHIVE_REASON_REMOTE = 'remote';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

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

    /** @var Collection<int, TaskTimeEntry> */
    #[ORM\OneToMany(targetEntity: TaskTimeEntry::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $timeEntries;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoard::class, inversedBy: 'tasks')]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoard $board,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator,
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
    ) {
        $this->id = Uuid::generate()->toString();
        // Since 1.16: cut to the column (VARCHAR(255)) whatever the caller (imports, host integrations).
        $this->title                = Text::cut($title, self::TITLE_MAX);
        $this->createdAt            = new DateTimeImmutable();
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
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): TaskBoard
    {
        return $this->board;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->title = Text::cut($title, self::TITLE_MAX);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreator(): object
    {
        return $this->creator;
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

    public function markCompleted(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->completedAt = new DateTimeImmutable();
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

    /** Since 1.15. */
    public function removeTimeEntry(TaskTimeEntry $entry): self
    {
        $this->timeEntries->removeElement($entry);

        return $this;
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
}
