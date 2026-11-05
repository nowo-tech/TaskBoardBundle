<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity]
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

    /** Since 1.25 (audit FP-L7): the creator's name, kept when the account is erased (`creator_id` SET NULL). */
    #[ORM\Column(name: 'creator_name', type: 'string', length: 180, nullable: true)]
    private ?string $creatorName;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'documents')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $content,
        // Since 1.25 (audit FP-L7): nullable, ON DELETE SET NULL; deleting the creator's account keeps the document.
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?object $creator,
        #[ORM\Column(type: 'integer')]
        private int $position = 0,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creatorName = $creator === null ? null : Text::cut(UserLabel::of($creator), Task::USER_NAME_MAX);
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
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->title = $title;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->content = $content;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /** The creator; null once the account was erased (since 1.25, see {@see self::getCreatorName()}). */
    public function getCreator(): ?object
    {
        return $this->creator;
    }

    /** Since 1.25: the creator's name when the document was created (kept when the account goes). */
    public function getCreatorName(): ?string
    {
        return $this->creatorName;
    }

    /**
     * Since 1.25 (audit FP-L7): the account is being erased — the document keeps its name snapshot (null with
     * `$forgetName`) and no longer points at the user.
     *
     * @internal used by the account erasure helper
     */
    public function detachCreator(bool $forgetName = false): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->creator = null;
        if ($forgetName) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->creatorName = null;
        }

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->position = $position;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
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
