<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.27.0 (audit WA-6): an application entity that holds tasks in a many-to-many collection (a join table), as a
 * host's reading list or sprint does.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_host_task_list')]
class HostTaskList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 100)]
    public string $name = 'List';

    /** @var Collection<int, Task> */
    #[ORM\ManyToMany(targetEntity: Task::class)]
    #[ORM\JoinTable(name: 'app_host_task_list_task')]
    public Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }
}
