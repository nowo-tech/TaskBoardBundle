<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
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

    /** Since 1.25 (audit FP-M1): the author's name, kept when the account is erased (`author_id` SET NULL). */
    #[ORM\Column(name: 'author_name', type: 'string', length: 180, nullable: true)]
    private ?string $authorName;

    /**
     * @param list<array{id: string, label: string}> $mentions
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        // Since 1.25 (audit FP-M1): nullable, ON DELETE SET NULL; the name stays in `author_name`.
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $author,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(type: 'json')]
        private array $mentions = [],
        // Since 1.25: the comment's date in the source tool (a provider sync); null = now.
        ?DateTimeImmutable $createdAt = null,
        // Since 2.10.0 (spec 216, FR-014): why it was written, e.g. "rule:42" (TaskChangeContext); null for a person's own.
        #[ORM\Column(name: 'cause', type: 'string', length: 191, nullable: true)]
        private ?string $cause = null,
    ) {
        $this->id         = Uuid::generate()->toString();
        $this->createdAt  = $createdAt ?? new DateTimeImmutable();
        $this->authorName = $author === null ? null : Text::cut(UserLabel::of($author), Task::USER_NAME_MAX);
    }

    /**
     * Since 1.25: a new author after a people re-association (no edit mark, no notification).
     *
     * @internal used by the provider sync's people services only
     */
    public function reassignAuthor(object $author): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->author = $author;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->authorName = Text::cut(UserLabel::of($author), Task::USER_NAME_MAX);

        return $this;
    }

    /**
     * Since 1.25 (audit FP-M1): the account is being erased — the comment keeps its name snapshot (null with
     * `$forgetName`) and no longer points at the user, so deleting the user deletes no comment.
     *
     * @internal used by the account erasure helper
     */
    public function detachAuthor(bool $forgetName = false): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->author = null;
        if ($forgetName) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->authorName = null;
        }

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    /** The author; null once the account was erased (since 1.25, see {@see self::getAuthorName()}). */
    public function getAuthor(): ?object
    {
        return $this->author;
    }

    /** Since 1.25: the author's name when the comment was written or last re-assigned. */
    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    /** Since 2.10.0 (spec 216): the cause it was written with (e.g. "rule:42"), shown through `comments.cause_labeler`. */
    public function getCause(): ?string
    {
        return $this->cause;
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

    /** Since 1.28.0: the same words in another format, from the sync: not an edit. */
    public function reformat(string $body): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->body = $body;

        return $this;
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
