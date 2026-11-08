<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_filter;
use function array_values;
use function in_array;
use function sort;

/**
 * Since 2.3.0 (spec 215, `recurrence.enabled`): a repeating task. The source of each new occurrence is the latest one, so
 * an edit to the current task carries forward. Gone with its board (which never touches time). The author is who the
 * occurrences are created as; erased, the column is null (`ON DELETE SET NULL`) and the series cannot run.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_task_board_task_recurrences_due', columns: ['status', 'next_date'])]
#[ORM\Index(name: 'idx_task_board_task_recurrences_latest', columns: ['latest_task_id'])]
class TaskRecurrence
{
    public const TABLE = 'task_board_task_recurrences';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_ENDED = 'ended';

    /** Reasons of a pause or a block a host may set; `plan` keeps the series active, retried on each run. */
    public const REASON_MANUAL = 'manual';

    public const REASON_AUTHOR = 'author_no_access';

    public const REASON_PLAN = 'plan';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'next_date', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $nextDate = null;

    /** @var list<string> Y-m-d */
    #[ORM\Column(name: 'skipped_dates', type: 'json')]
    private array $skippedDates = [];

    /** After completion: the next completion moves on two periods. */
    #[ORM\Column(name: 'skip_next', type: 'boolean', options: ['default' => false])]
    private bool $skipNext = false;

    /** How many occurrences exist, the first task included (for an end after N occurrences). */
    #[ORM\Column(type: 'integer')]
    private int $occurrences = 1;

    #[ORM\Column(name: 'author_name', type: 'string', length: 180, nullable: true)]
    private ?string $authorName = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $author = null;

    /**
     * @param list<int> $weekdays
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoard::class)]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoard $board,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'latest_task_id', nullable: true, onDelete: 'SET NULL')]
        private ?Task $latestTask,
        object $author,
        #[ORM\Column(type: 'string', length: 16)]
        private string $mode,
        #[ORM\Column(type: 'string', length: 8)]
        private string $unit,
        #[ORM\Column(name: 'interval_count', type: 'integer')]
        private int $interval,
        #[ORM\Column(name: 'anchor_date', type: 'date_immutable')]
        private DateTimeImmutable $anchor,
        #[ORM\Column(type: 'json')]
        private array $weekdays = [],
        #[ORM\Column(name: 'month_day', type: 'smallint', nullable: true)]
        private ?int $monthDay = null,
        #[ORM\Column(name: 'end_date', type: 'date_immutable', nullable: true)]
        private ?DateTimeImmutable $endDate = null,
        #[ORM\Column(name: 'end_count', type: 'integer', nullable: true)]
        private ?int $endCount = null,
        #[ORM\Column(name: 'next_on_completion', type: 'boolean', options: ['default' => false])]
        private bool $nextOnCompletion = false,
        #[ORM\Column(name: 'lead_days', type: 'smallint')]
        private int $leadDays = 1,
        #[ORM\Column(name: 'copy_subtasks', type: 'boolean', options: ['default' => true])]
        private bool $copySubtasks = true,
        #[ORM\Column(name: 'copy_members', type: 'boolean', options: ['default' => true])]
        private bool $copyMembers = true,
        #[ORM\ManyToOne(targetEntity: BoardColumn::class)]
        #[ORM\JoinColumn(name: 'start_column_id', nullable: true, onDelete: 'SET NULL')]
        private ?BoardColumn $startColumn = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->setAuthor($author);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): TaskBoard
    {
        return $this->board;
    }

    public function getLatestTask(): ?Task
    {
        return $this->latestTask;
    }

    public function rule(): TaskRecurrenceRule
    {
        return new TaskRecurrenceRule($this->mode, $this->unit, $this->interval, $this->anchor, $this->weekdays, $this->monthDay, $this->endDate, $this->endCount);
    }

    public function change(TaskRecurrenceRule $rule, bool $nextOnCompletion, int $leadDays, bool $copySubtasks, bool $copyMembers): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mode = $rule->mode;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->unit = $rule->unit;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->interval = $rule->interval;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->anchor = $rule->anchor;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->weekdays = $rule->weekdays;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->monthDay = $rule->monthDay;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->endDate = $rule->endDate;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->endCount = $rule->endCount;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextOnCompletion = $nextOnCompletion;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->leadDays = $leadDays;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->copySubtasks = $copySubtasks;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->copyMembers = $copyMembers;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isNextOnCompletion(): bool
    {
        return $this->nextOnCompletion;
    }

    public function getLeadDays(): int
    {
        return $this->leadDays;
    }

    public function copiesSubtasks(): bool
    {
        return $this->copySubtasks;
    }

    public function copiesMembers(): bool
    {
        return $this->copyMembers;
    }

    public function getStartColumn(): ?BoardColumn
    {
        return $this->startColumn;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getNextDate(): ?DateTimeImmutable
    {
        return $this->nextDate;
    }

    /** @return list<string> */
    public function getSkippedDates(): array
    {
        return $this->skippedDates;
    }

    public function isSkipNext(): bool
    {
        return $this->skipNext;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function getAuthor(): ?object
    {
        return $this->author;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @internal state changes go through {@see TaskRecurrenceManager} */
    public function setAuthor(?object $author, bool $forgetName = false): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->author = $author;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->authorName = $author !== null ? Text::cut(UserLabel::of($author), Task::USER_NAME_MAX) : ($forgetName ? null : $this->authorName);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** @internal */
    public function setStatus(string $status, ?string $reason = null): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = $status;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reason = $reason;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** @internal */
    public function setNextDate(?DateTimeImmutable $date): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextDate = $date?->setTime(0, 0);

        return $this;
    }

    /** @internal */
    public function setSkipNext(bool $skip): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->skipNext = $skip;

        return $this;
    }

    /** @internal */
    public function skip(string $date, bool $skip): self
    {
        $dates = $this->skippedDates;
        if ($skip && !in_array($date, $dates, true)) {
            $dates[] = $date;
        }
        if (!$skip) {
            $dates = array_values(array_filter($dates, static fn (string $kept): bool => $kept !== $date));
        }
        sort($dates);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->skippedDates = $dates;

        return $this;
    }

    /** @internal a new occurrence was created from the series */
    public function advance(Task $latest): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->latestTask = $latest;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        ++$this->occurrences;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->skipNext = false;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** @internal the latest occurrence moved to another board (spec 217) */
    public function moveTo(TaskBoard $board, ?BoardColumn $column): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->board = $board;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->startColumn = $column;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
