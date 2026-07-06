<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function in_array;

#[ORM\Entity(repositoryClass: DoctrineOrmTaskRepository::class)]
#[ORM\Table(name: 'task_board_tasks')]
#[ORM\Index(name: 'task_board_tasks_board_idx', columns: ['board_id'])]
class Task
{
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

    /** @var Collection<int, TaskChangeHistory> */
    #[ORM\OneToMany(targetEntity: TaskChangeHistory::class, mappedBy: 'task', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
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
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
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
        $this->id                   = Uuid::generate()->toString();
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
        $this->title     = $title;
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
        $this->column    = $column;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updatedAt   = new DateTimeImmutable();

        return $this;
    }

    public function getPriority(): TaskPriority
    {
        return $this->priority;
    }

    public function setPriority(TaskPriority $priority): self
    {
        $this->priority  = $priority;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position  = $position;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $estimatedMinutes): self
    {
        $this->estimatedMinutes = $estimatedMinutes;
        $this->updatedAt        = new DateTimeImmutable();

        return $this;
    }

    public function getDueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?DateTimeImmutable $dueAt): self
    {
        $this->dueAt     = $dueAt;
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
        $this->tags      = $this->normalizeTags($tags);
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
        $this->completedAt = new DateTimeImmutable();
        $this->updatedAt   = new DateTimeImmutable();

        return $this;
    }

    public function markIncomplete(): self
    {
        $this->completedAt = null;
        $this->updatedAt   = new DateTimeImmutable();

        return $this;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
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

    public function addTimeSeconds(int $seconds): self
    {
        if ($seconds > 0) {
            $this->totalTimeSeconds += $seconds;
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

    /** @param list<string> $tags */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && !in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        sort($normalized);

        return $normalized;
    }
}
