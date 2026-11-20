<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.27.0 (audit WA-1): an application entity that references TaskBoard rows (a board and a task, no cascade), as a
 * host's schedule, alert or automation rule does.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_host_project')]
class HostProject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $name = 'Project';

    #[ORM\ManyToOne(targetEntity: TaskBoard::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?TaskBoard $board = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Task $task = null;
}
