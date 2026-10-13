<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before granting or denying access to a board (and its columns, tasks, links and members).
 *
 * The default decision (creator, team member or manage role) is exposed via isDefaultGranted();
 * listeners may override it with grant() / deny().
 */
final class BoardAccessCheckEvent extends Event
{
    private bool $granted = false;

    private bool $denied = false;

    public function __construct(
        private readonly object $subject,
        private readonly TaskBoard $board,
        private readonly bool $defaultGranted = false,
    ) {
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    public function getBoard(): TaskBoard
    {
        return $this->board;
    }

    public function isDefaultGranted(): bool
    {
        return $this->defaultGranted;
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
}
