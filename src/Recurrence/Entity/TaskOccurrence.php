<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 2.3.0 (spec 215, `recurrence.enabled`): one occurrence of a series, created or missed. The unique series + key is
 * what makes a creation happen once: `date:<Y-m-d>` (a fixed date) or `done:<task id>` (after a completion). A deleted task
 * leaves the row with no task (`ON DELETE SET NULL`); a missed date has none from the start.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_task_board_task_occurrences_key', columns: ['recurrence_id', 'occurrence_key'])]
#[ORM\Index(name: 'idx_task_board_task_occurrences_task', columns: ['task_id'])]
class TaskOccurrence
{
    public const TABLE = 'task_board_task_occurrences';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskRecurrence::class)]
        #[ORM\JoinColumn(name: 'recurrence_id', nullable: false, onDelete: 'CASCADE')]
        private TaskRecurrence $recurrence,
        #[ORM\Column(name: 'occurrence_key', type: 'string', length: 64)]
        private string $occurrenceKey,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'SET NULL')]
        private ?Task $task = null,
        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        private bool $missed = false,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRecurrence(): TaskRecurrence
    {
        return $this->recurrence;
    }

    public function getOccurrenceKey(): string
    {
        return $this->occurrenceKey;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(Task $task): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->task = $task;

        return $this;
    }

    public function isMissed(): bool
    {
        return $this->missed;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
