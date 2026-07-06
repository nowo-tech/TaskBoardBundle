<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDocumentRepository;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmTaskDocumentRepository::class)]
#[ORM\Table(name: 'task_board_task_documents')]
class TaskDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'documents')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $content,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator,
        #[ORM\Column(type: 'integer')]
        private int $position = 0,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
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

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content   = $content;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreator(): object
    {
        return $this->creator;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
