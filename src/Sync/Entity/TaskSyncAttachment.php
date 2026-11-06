<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 1.23: a provider file stored by the host for a task (reused while the remote id, size and date are the
 * same).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_attachments')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_attachments_task_remote', columns: ['task_id', 'remote_id'])]
class TaskSyncAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskSyncItem::class)]
        #[ORM\JoinColumn(name: 'item_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncItem $item,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\Column(name: 'remote_id', type: 'string', length: 128)]
        private string $remoteId,
        #[ORM\Column(name: 'remote_size', type: 'bigint', nullable: true)]
        private ?int $remoteSize,
        #[ORM\Column(name: 'remote_updated_at', type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $remoteUpdatedAt,
        #[ORM\Column(name: 'stored_ref', type: 'string', length: 191)]
        private string $storedRef,
        #[ORM\Column(name: 'stored_url', type: 'string', length: 2048)]
        private string $storedUrl,
        #[ORM\Column(type: 'string', length: 255)]
        private string $name,
        #[ORM\Column(type: 'string', length: 127)]
        private string $mime,
        #[ORM\Column(type: 'bigint')]
        private int $size,
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        private ?string $sha256 = null,
        /** The attachment link shown on the task (null for an inline image or a comment's file). */
        #[ORM\ManyToOne(targetEntity: TaskLink::class)]
        #[ORM\JoinColumn(name: 'link_id', nullable: true, onDelete: 'SET NULL')]
        private ?TaskLink $link = null,
    ) {
        $this->id = Uuid::generate()->toString();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getItem(): TaskSyncItem
    {
        return $this->item;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getLink(): ?TaskLink
    {
        return $this->link;
    }

    /** Whether the remote file is the one stored (same size and date). */
    public function matches(?int $size, ?DateTimeImmutable $updatedAt): bool
    {
        return $size === ($this->remoteSize ?? null)
            && $updatedAt?->getTimestamp() === $this->remoteUpdatedAt?->getTimestamp();
    }

    /** The remote file changed: the row now describes the new stored copy (updated in place, the key stays unique). */
    public function replace(?int $remoteSize, ?DateTimeImmutable $remoteUpdatedAt, StoredAttachment $stored, string $name, string $mime, ?TaskLink $link): void
    {
        $this->remoteSize      = $remoteSize;
        $this->remoteUpdatedAt = $remoteUpdatedAt;
        $this->storedRef       = $stored->ref;
        $this->storedUrl       = $stored->url;
        $this->name            = $name;
        $this->mime            = $mime;
        $this->size            = $stored->size;
        $this->sha256          = $stored->sha256;
        $this->link            = $link;
    }

    public function stored(): StoredAttachment
    {
        return new StoredAttachment($this->storedRef, $this->storedUrl, $this->name, $this->size, $this->mime, $this->sha256);
    }
}
