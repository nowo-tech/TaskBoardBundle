<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 1.23: a provider comment imported as a task comment (imported once; updated when the provider edits it and
 * nobody edited it here).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_comments')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_comments_item_remote', columns: ['item_id', 'remote_id'])]
class TaskSyncComment
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskSyncItem::class)]
        #[ORM\JoinColumn(name: 'item_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncItem $item,
        #[ORM\ManyToOne(targetEntity: TaskComment::class)]
        #[ORM\JoinColumn(name: 'comment_id', nullable: false, onDelete: 'CASCADE')]
        private TaskComment $comment,
        #[ORM\Column(name: 'remote_id', type: 'string', length: 128)]
        private string $remoteId,
        /** Hash of the remote body last written. */
        #[ORM\Column(name: 'body_hash', type: 'string', length: 64)]
        private string $bodyHash,
        /** Hash of the comment body as written here (an edit here changes the comment, not this). */
        #[ORM\Column(name: 'local_hash', type: 'string', length: 64)]
        private string $localHash,
    ) {
        $this->id = Uuid::generate()->toString();
    }

    /** Since 1.25: the remote author (null = unknown). */
    #[ORM\ManyToOne(targetEntity: TaskSyncPerson::class)]
    #[ORM\JoinColumn(name: 'author_person_id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskSyncPerson $authorPerson = null;

    public function getAuthorPerson(): ?TaskSyncPerson
    {
        return $this->authorPerson;
    }

    public function setAuthorPerson(?TaskSyncPerson $person): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->authorPerson = $person;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getItem(): TaskSyncItem
    {
        return $this->item;
    }

    public function getComment(): TaskComment
    {
        return $this->comment;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getBodyHash(): string
    {
        return $this->bodyHash;
    }

    public function getLocalHash(): string
    {
        return $this->localHash;
    }

    public function written(string $bodyHash, string $localHash): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->bodyHash = $bodyHash;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->localHash = $localHash;

        return $this;
    }
}
