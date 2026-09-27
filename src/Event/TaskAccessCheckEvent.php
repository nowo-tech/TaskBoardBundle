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
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->denied = false;
    }

    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->denied = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
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
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->readOnly = true;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
