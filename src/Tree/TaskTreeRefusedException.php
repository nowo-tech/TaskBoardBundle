<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use DomainException;

/**
 * Since 2.20.0 (Beacon spec 234, T004): a write that changes the task tree (a parent change, a subtask created under a
 * parent) refused before anything was written. `reason` is stable and machine-readable (one of the constants); the Task
 * API answers each with its own `ECODE` (`PARENT_001` to `PARENT_005`, a missing task as `ACCESS_001`).
 */
final class TaskTreeRefusedException extends DomainException
{
    /** The task or the new parent does not exist (any more). */
    public const NOT_FOUND = 'not_found';

    /** The new parent is on another board: a task tree never spans boards. */
    public const OTHER_BOARD = 'other_board';

    /** The new parent is the task itself. */
    public const SELF = 'self';

    /** The new parent is one of the task's descendants (it would close a cycle). */
    public const DESCENDANT = 'descendant';

    /**
     * The write would put a task deeper than `max_subtask_depth` (more ancestors than the limit), counting the height of
     * a moved subtree. Also when a walk meets a loop already in the data or passes {@see TaskTreeLimits::SAFETY_CAP}
     * levels: fails closed.
     */
    public const TOO_DEEP = 'too_deep';

    /**
     * A concurrent write held the rows (a deadlock or a lock wait that timed out): nothing was written, try again. Since
     * 2.21.0 (Beacon spec 239, audits SF-L1 / SF-L2) also a move, or a column delete or retype, that found its task
     * changed by a concurrent write once the rows were locked (another board, column, archive or completion state than
     * the one it checked), or an out-of-date copy holding unsaved changes: the Task API answers 409 `PARENT_004`, the
     * board shows the "changed meanwhile" message and reloads.
     */
    public const CONFLICT = 'conflict';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'The task or its new parent was not found.');
    }

    public static function otherBoard(): self
    {
        return new self(self::OTHER_BOARD, 'The new parent is on another board.');
    }

    public static function self(): self
    {
        return new self(self::SELF, 'A task cannot be its own parent.');
    }

    public static function descendant(): self
    {
        return new self(self::DESCENDANT, 'The new parent is one of the task\'s subtasks.');
    }

    public static function tooDeep(): self
    {
        return new self(self::TOO_DEEP, 'The task tree would be deeper than allowed.');
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT, 'The task tree changed meanwhile: try again.');
    }
}
