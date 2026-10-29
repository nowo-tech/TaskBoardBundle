<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_column;
use function in_array;

/**
 * Since 1.16: a comment on a task (plain text with line breaks), with the people it mentions stored structured
 * (`mentions`: list of {id, label} of valid candidates). Deleting keeps the row (soft delete) so the activity stream
 * keeps its place; the body is cleared.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_task_comments')]
#[ORM\Index(name: 'task_board_task_comments_task_idx', columns: ['task_id', 'created_at'])]
class TaskComment
{
    /** Longest comment body (characters). */
    public const MAX_LENGTH = 5000;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /**
     * @param list<array{id: string, label: string}> $mentions
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'CASCADE')]
        private object $author,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(type: 'json')]
        private array $mentions = [],
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

    public function getAuthor(): object
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    /** @return list<array{id: string, label: string}> */
    public function getMentions(): array
    {
        return $this->mentions;
    }

    /** @return list<string> */
    public function getMentionedIds(): array
    {
        return array_values(array_map(strval(...), array_column($this->mentions, 'id')));
    }

    public function mentions(string $userId): bool
    {
        return in_array($userId, $this->getMentionedIds(), true);
    }

    /**
     * @param list<array{id: string, label: string}> $mentions
     */
    public function edit(string $body, array $mentions): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->body = $body;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mentions = $mentions;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->editedAt = new DateTimeImmutable();

        return $this;
    }

    public function markDeleted(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->body = '';
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mentions = [];
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->deletedAt = new DateTimeImmutable();

        return $this;
    }
}
