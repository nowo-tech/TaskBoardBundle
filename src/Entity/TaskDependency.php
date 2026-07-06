<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDependencyRepository;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmTaskDependencyRepository::class)]
#[ORM\Table(name: 'task_board_task_dependencies')]
#[ORM\UniqueConstraint(name: 'task_board_dep_unique', columns: ['source_task_id', 'target_task_id', 'dependency_type'])]
class TaskDependency
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'outgoingDependencies')]
        #[ORM\JoinColumn(name: 'source_task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $sourceTask,
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'incomingDependencies')]
        #[ORM\JoinColumn(name: 'target_task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $targetTask,
        #[ORM\Column(name: 'dependency_type', type: 'string', length: 32, enumType: TaskDependencyType::class)]
        private TaskDependencyType $dependencyType,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceTask(): Task
    {
        return $this->sourceTask;
    }

    public function getTargetTask(): Task
    {
        return $this->targetTask;
    }

    public function getDependencyType(): TaskDependencyType
    {
        return $this->dependencyType;
    }
}
