<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.26.1 (audit WA-1): an application entity that references a task with cascade persist.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_host_cascade_link')]
class HostCascadeLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $name = 'Link';

    #[ORM\ManyToOne(targetEntity: Task::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Task $task = null;
}
