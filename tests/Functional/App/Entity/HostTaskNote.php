<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.29.0: an application entity with a many-to-one to a task and no cascade, as Beacon's notes and files point at
 * TaskBoard rows. With the private entity manager the host sets it with `getReference(Task::class, $id)`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_host_task_note')]
class HostTaskNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $text = 'Note';

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Task $task = null;
}
