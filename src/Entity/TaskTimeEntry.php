<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskTimeEntryRepository;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmTaskTimeEntryRepository::class)]
#[ORM\Table(name: 'task_board_task_time_entries')]
class TaskTimeEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'timeEntries')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(type: 'integer')]
        private int $minutes,
        #[ORM\Column(name: 'logged_at', type: 'datetime_immutable')]
        private DateTimeImmutable $loggedAt,
        #[ORM\Column(type: 'string', length: 512, nullable: true)]
        private ?string $description = null,
    ) {
        $this->id = Uuid::generate()->toString();
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
}
