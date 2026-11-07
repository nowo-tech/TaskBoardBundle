<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.1.0 (spec 214, `task_numbers.enabled`): one number handed out in a scope. The primary key is what makes a number
 * unique. A deleted task leaves its row with no task (`ON DELETE SET NULL`), so the number is retired and answers like an
 * unknown one. A task moved to another scope keeps its old row: the old number stays an alias.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_task_board_task_numbers_task', columns: ['task_id'])]
class TaskNumberEntry
{
    public const TABLE = 'task_board_task_numbers';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'scope_key', type: 'string', length: 191)]
        private string $scopeKey,
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        private int $number,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'SET NULL')]
        private ?Task $task,
    ) {
    }

    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getTask(): ?Task
    {
        return $this->task;
    }
}
