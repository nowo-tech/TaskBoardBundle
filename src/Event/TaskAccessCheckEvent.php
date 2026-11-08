<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before granting or denying access to a task.
 *
 * Since 2.4.0 the event carries an action ({@see self::getAction()}): {@see self::ACTION_VIEW}, the historical meaning,
 * or {@see self::ACTION_TRACK_TIME}. A listener that ignores the action answers every action alike, which keeps the
 * behaviour it had before 2.4.0 (see {@see TaskBoardResourceAccessChecker::canTrackTime()}).
 */
final class TaskAccessCheckEvent extends Event
{
    /** Viewing (and, unless marked read-only, editing) the task: the historical meaning of the event. */
    public const ACTION_VIEW = 'view';

    /**
     * Since 2.4.0 (spec 213): logging time on the task through the Task API (`POST /v2/team/{team_id}/time_entries` with a
     * `tid`). grant() allows it without the edit right (a project member who may track time); deny() refuses it even to an
     * editor, and sticks: a later grant() (a lower-priority listener) does not clear it (audit II-L1). With no answer,
     * or grant() together with markReadOnly(), the edit right decides, as before 2.4.0.
     */
    public const ACTION_TRACK_TIME = 'track_time';

    private bool $granted = false;

    private bool $denied = false;

    private bool $readOnly = false;

    public function __construct(
        private readonly object $subject,
        private readonly Task $task,
        // Since 2.4.0.
        private readonly string $action = self::ACTION_VIEW,
    ) {
    }

    /** Since 2.4.0: what is being decided ({@see self::ACTION_VIEW} or {@see self::ACTION_TRACK_TIME}). */
    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    /**
     * For {@see self::ACTION_TRACK_TIME}, a grant after a deny() is ignored: the deny sticks (since 2.4.0, audit II-L1).
     * For {@see self::ACTION_VIEW}, the last call wins, as before.
     */
    public function grant(): void
    {
        if ($this->denied && $this->action === self::ACTION_TRACK_TIME) {
            return;
        }
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
