<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableService;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * A manual time entry of a task (since 1.15).
 *
 * Since 1.23.2 the entry outlives its task: deleting the task detaches the entry (`task` = null, `ON DELETE SET NULL`)
 * instead of deleting the tracked time. The task title, board id and external id are kept as snapshots (filled at
 * creation, refreshed on rename and right before the task is removed) so a detached entry still reads in timesheets
 * and reports — read them through {@see self::getTaskTitle()} / {@see self::getBoardId()} / {@see self::getTaskExternalId()}.
 *
 * Since 1.27.0 (owner rule, audit GWd-M2) the entry also outlives its user: deleting the user leaves `user` = null
 * (`ON DELETE SET NULL`) and {@see self::getUserName()} keeps the name it was logged with, until it is forgotten.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_task_time_entries')]
class TaskTimeEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'timeEntries')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Task $task;

    /** Since 1.23.2: the task title when the entry was logged, refreshed on rename and before the task is removed. */
    #[ORM\Column(name: 'task_title_snapshot', type: 'string', length: 255, nullable: true)]
    private ?string $taskTitleSnapshot = null;

    /** Since 1.23.2: the id of the task's board (kept after the task is removed). */
    #[ORM\Column(name: 'board_id_snapshot', type: 'string', length: 36, nullable: true)]
    private ?string $boardIdSnapshot = null;

    /** Since 1.23.2: the external id of an imported / synced task (its import link), null for a native task. */
    #[ORM\Column(name: 'task_external_id', type: 'string', length: 128, nullable: true)]
    private ?string $taskExternalId = null;

    /** Since 1.27.0: null once the user was deleted. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?object $user;

    /** Since 1.27.0: the user's name when the entry was logged, kept when the user is deleted; null once forgotten. */
    #[ORM\Column(name: 'user_name', type: 'string', length: Task::USER_NAME_MAX, nullable: true)]
    private ?string $userName;

    /** Since 2.12.0 (Beacon D-20261111-12): billable flag; null = not set (rows logged before 2.12). */
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $billable = null;

    public function __construct(
        Task $task,
        object $user,
        #[ORM\Column(type: 'integer')]
        private int $minutes,
        #[ORM\Column(name: 'logged_at', type: 'datetime_immutable')]
        private DateTimeImmutable $loggedAt,
        #[ORM\Column(type: 'string', length: 512, nullable: true)]
        private ?string $description = null,
    ) {
        $this->id       = Uuid::generate()->toString();
        $this->task     = $task;
        $this->user     = $user;
        $this->userName = Text::cut(UserLabel::of($user), Task::USER_NAME_MAX);
        $this->refreshTaskSnapshot();
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The task, or null once the task was deleted (since 1.23.2): read the snapshots then.
     */
    public function getTask(): ?Task
    {
        return $this->task;
    }

    /** Since 1.23.2: whether the task of the entry was deleted (the entry keeps its time and snapshots). */
    public function isDetached(): bool
    {
        return !$this->task instanceof Task;
    }

    /**
     * Since 1.23.2: copies the task title, board id and external id into the snapshot columns (no-op once detached).
     */
    public function refreshTaskSnapshot(): self
    {
        if (!$this->task instanceof Task) {
            return $this;
        }

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskTitleSnapshot = Text::cut($this->task->getTitle(), Task::TITLE_MAX);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardIdSnapshot = $this->task->getBoard()->getId();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskExternalId = Text::cutNullable($this->task->getExternalId(), 128);

        return $this;
    }

    /**
     * Since 1.23.2: refreshes the snapshots and drops the task reference — called when the task is removed, so the
     * time stays (the database does the same with `ON DELETE SET NULL` for deletes that bypass the ORM).
     */
    public function detachFromTask(): self
    {
        $this->refreshTaskSnapshot();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->task = null;

        return $this;
    }

    /** Since 1.23.2: the live task title, or the snapshot once the task was deleted (null: legacy row never backfilled). */
    public function getTaskTitle(): ?string
    {
        return $this->task instanceof Task ? $this->task->getTitle() : $this->taskTitleSnapshot;
    }

    /** Since 1.23.2: the live board id, or the snapshot once the task was deleted. */
    public function getBoardId(): ?string
    {
        return $this->task instanceof Task ? $this->task->getBoard()->getId() : $this->boardIdSnapshot;
    }

    /** Since 1.23.2. */
    public function getTaskTitleSnapshot(): ?string
    {
        return $this->taskTitleSnapshot;
    }

    /** Since 1.23.2. */
    public function getBoardIdSnapshot(): ?string
    {
        return $this->boardIdSnapshot;
    }

    /** Since 1.23.2. */
    public function getTaskExternalId(): ?string
    {
        return $this->taskExternalId;
    }

    /** Null once the user was deleted (since 1.27.0): read {@see self::getUserName()} then. */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /** Since 1.27.0: the name it was logged with (null: forgotten, or a row of 1.26 never backfilled). */
    public function getUserName(): ?string
    {
        return $this->userName;
    }

    /** Since 1.27.0: fills the name of an entry logged before 1.27. */
    public function backfillUserName(): self
    {
        if ($this->user !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->userName ??= Text::cut(UserLabel::of($this->user), Task::USER_NAME_MAX);
        }

        return $this;
    }

    /** Since 1.27.0: drops the user reference, and the name when `$forgetName`; the time stays. */
    public function detachUser(bool $forgetName = false): self
    {
        if ($forgetName) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->userName = null;
        } elseif ($this->user !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->userName ??= Text::cut(UserLabel::of($this->user), Task::USER_NAME_MAX);
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->user = null;

        return $this;
    }

    public function getMinutes(): int
    {
        return $this->minutes;
    }

    public function getLoggedAt(): DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** Since 2.12.0: billable flag (null = not set). */
    public function getBillable(): ?bool
    {
        return $this->billable;
    }

    /**
     * Since 2.12.0: sets the billable flag; true when it changed. No checks here: use
     * {@see TaskTimeBillableService}, which authorises, honours the period lock and audits.
     *
     * @internal used by the bundle's services (a new entry's value, the billable service)
     */
    public function markBillable(?bool $billable): bool
    {
        if ($this->billable === $billable) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->billable = $billable;

        return true;
    }
}
