<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before granting or denying access to a board (and its columns, tasks, links and members).
 *
 * The default decision (creator, team member or manage role) is exposed via isDefaultGranted();
 * listeners may override it with grant() / deny().
 */
final class BoardAccessCheckEvent extends Event
{
    /** Viewing (and, unless marked read-only, editing) the board: the historical meaning of the event. */
    public const ACTION_VIEW = 'view';

    /**
     * Since 1.23: managing the board's provider connection (link, refresh, unlink). A listener that does not look at
     * {@see self::getAction()} and grants every action widens it to "anyone who can edit the board".
     */
    public const ACTION_MANAGE_CONNECTION = 'manage_connection';

    /**
     * Since 1.25: bringing tasks in from outside — a file import into the board or under a task, and the provider
     * connection (link, sync, people mapping, unlink). Granted by default to whoever may edit the board (and, for the
     * connection, manage it); a host denies it to narrow imports to a dedicated permission (e.g. Beacon's
     * `project.tasks.import`).
     */
    public const ACTION_IMPORT_TASKS = 'import_tasks';

    /**
     * Since 1.25: managing the board itself — emptying or deleting it ({@see TaskBoardEraser}).
     * Default: the board's creator or a `security.manage_roles` holder.
     */
    public const ACTION_MANAGE = 'manage';

    /**
     * Since 2.5.0 (TB-13): managing the board's columns — add, edit, reorder and delete a column, and save, apply or delete
     * a column set ({@see TaskBoardResourceAccessChecker::canManageColumns()}). Default: the edit decision, as before; a
     * host denies it to narrow column management to a dedicated permission. A deny sticks: a later grant() is ignored.
     */
    public const ACTION_MANAGE_COLUMNS = 'manage_columns';

    private bool $granted = false;

    private bool $denied = false;

    private bool $readOnly = false;

    public function __construct(
        private readonly object $subject,
        private readonly TaskBoard $board,
        private readonly bool $defaultGranted = false,
        // Since 1.23.
        private readonly string $action = self::ACTION_VIEW,
    ) {
    }

    /** Since 1.23: what is being decided ({@see self::ACTION_VIEW}, {@see self::ACTION_MANAGE_CONNECTION}, {@see self::ACTION_IMPORT_TASKS}, {@see self::ACTION_MANAGE} or, since 2.5.0, {@see self::ACTION_MANAGE_COLUMNS}). */
    public function getAction(): string
    {
        return $this->action;
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

    /**
     * For {@see self::ACTION_MANAGE_COLUMNS}, a grant after a deny() is ignored: the deny sticks (since 2.5.0, as
     * TaskAccessCheckEvent::ACTION_TRACK_TIME). For the other actions the last call wins, as before.
     */
    public function grant(): void
    {
        if ($this->denied && $this->action === self::ACTION_MANAGE_COLUMNS) {
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

    /**
     * Read-only access (since 1.10): the user may view the board but not change it (columns, new tasks, import) nor
     * edit its tasks unless a TASK_ACCESS_CHECK listener grants the task explicitly.
     */
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
