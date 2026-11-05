<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 1.25: a task member the sync added for a remote person. Only these members are removed or moved by a sync or
 * a re-association; members added by hand never are.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_members')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_members_member', columns: ['task_member_id'])]
#[ORM\Index(name: 'idx_task_board_sync_members_person', columns: ['person_id'])]
class TaskSyncMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskSyncItem::class)]
        #[ORM\JoinColumn(name: 'item_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncItem $item,
        #[ORM\ManyToOne(targetEntity: TaskMember::class)]
        #[ORM\JoinColumn(name: 'task_member_id', nullable: false, onDelete: 'CASCADE')]
        private TaskMember $member,
        #[ORM\ManyToOne(targetEntity: TaskSyncPerson::class)]
        #[ORM\JoinColumn(name: 'person_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncPerson $person,
        #[ORM\Column(name: 'member_role', type: 'string', length: 32)]
        private string $memberRole,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getItem(): TaskSyncItem
    {
        return $this->item;
    }

    public function getMember(): TaskMember
    {
        return $this->member;
    }

    public function getPerson(): TaskSyncPerson
    {
        return $this->person;
    }

    public function getMemberRole(): string
    {
        return $this->memberRole;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
