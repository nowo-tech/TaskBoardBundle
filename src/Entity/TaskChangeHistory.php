<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskChangeHistoryRepository;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmTaskChangeHistoryRepository::class)]
#[ORM\Table(name: 'task_board_task_change_history')]
#[ORM\Index(name: 'task_board_change_history_task_idx', columns: ['task_id'])]
class TaskChangeHistory
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'changeHistory')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $user,
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
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getUser(): object
    {
        return $this->user;
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
