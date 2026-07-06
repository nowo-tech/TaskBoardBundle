<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskMemberRepository;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmTaskMemberRepository::class)]
#[ORM\Table(name: 'task_board_task_members')]
#[ORM\UniqueConstraint(name: 'task_board_member_unique', columns: ['task_id', 'user_id', 'member_role'])]
class TaskMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'members')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'member_role', type: 'string', length: 32, enumType: TaskMemberRole::class)]
        private TaskMemberRole $memberRole,
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

    public function setTask(Task $task): self
    {
        $this->task = $task;

        return $this;
    }

    public function getUser(): object
    {
        return $this->user;
    }

    public function getMemberRole(): TaskMemberRole
    {
        return $this->memberRole;
    }
}
