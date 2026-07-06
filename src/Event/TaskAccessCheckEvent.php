<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before granting or denying access to a task.
 */
final class TaskAccessCheckEvent extends Event
{
    private bool $granted = false;

    private bool $denied = false;

    private bool $readOnly = false;

    public function __construct(
        private readonly object $subject,
        private readonly Task $task,
    ) {
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function grant(): void
    {
        $this->granted = true;
        $this->denied  = false;
    }

    public function deny(): void
    {
        $this->denied  = true;
        $this->granted = false;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    public function markReadOnly(): void
    {
        $this->readOnly = true;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
