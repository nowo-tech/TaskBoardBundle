<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task_board_task_change_history')]
#[ORM\Index(name: 'task_board_change_history_task_idx', columns: ['task_id'])]
class TaskChangeHistory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'user_name', type: 'string', length: 180, nullable: true)]
    private ?string $userName;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'changeHistory')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\ManyToOne(targetEntity: User::class)]
        // Since 1.28.0 (audit GWf-L1): the record outlives its author's account, with the name in `userName`.
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?object $user,
        #[ORM\Column(name: 'change_type', type: 'string', length: 32, enumType: TaskChangeType::class)]
        private TaskChangeType $changeType,
        #[ORM\Column(name: 'old_value', type: 'text', nullable: true)]
        private ?string $oldValue = null,
        #[ORM\Column(name: 'new_value', type: 'text', nullable: true)]
        private ?string $newValue = null,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $context = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->userName  = $user === null ? null : Text::cut(UserLabel::of($user), Task::USER_NAME_MAX);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    /** Since 1.28.0: null once the account is gone; {@see self::getUserName()} keeps the name unless it was forgotten. */
    public function getUser(): ?object
    {
        return $this->user;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    /** Since 1.28.0: for rows written before the snapshot existed. */
    public function backfillUserName(): self
    {
        if ($this->userName === null && $this->user !== null) {
            $this->userName = Text::cut(UserLabel::of($this->user), Task::USER_NAME_MAX);
        }

        return $this;
    }

    /** Since 1.28.0: the row stops pointing at the user; with `$forgetName` the name goes too. The fact of the change stays. */
    public function detachUser(bool $forgetName = false): self
    {
        $this->backfillUserName();
        $this->user = null;
        if ($forgetName) {
            $this->userName = null;
        }

        return $this;
    }

    public function getChangeType(): TaskChangeType
    {
        return $this->changeType;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
