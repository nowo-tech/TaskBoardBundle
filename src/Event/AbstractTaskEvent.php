<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A task write, dispatched after it is flushed (since 1.10): the task and the user who made the change.
 */
abstract class AbstractTaskEvent extends Event
{
    /**
     * @param Task $task The task, as saved
     * @param object $actor The user who made the change
     */
    public function __construct(
        private readonly Task $task,
        private readonly object $actor,
    ) {
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getActor(): object
    {
        return $this->actor;
    }
}
