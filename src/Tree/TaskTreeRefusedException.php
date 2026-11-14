<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

use DomainException;

/**
 * Since 2.20.0 (Beacon spec 234, T004): a parent change refused before anything was written. `reason` is stable and
 * machine-readable (one of the constants); the Task API answers each with its own `ECODE` (`PARENT_001` to
 * `PARENT_004`, a missing task as `ACCESS_001`).
 */
final class TaskReparentRefusedException extends DomainException
{
    /** The task or the new parent does not exist (any more). */
    public const NOT_FOUND = 'not_found';

    /** The new parent is on another board: a task tree never spans boards. */
    public const OTHER_BOARD = 'other_board';

    /** The new parent is the task itself. */
    public const SELF = 'self';

    /** The new parent is one of the task's descendants (it would close a cycle). */
    public const DESCENDANT = 'descendant';

    /** A concurrent write held the rows (a deadlock or a lock wait that timed out): nothing was written, try again. */
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

    public static function conflict(): self
    {
        return new self(self::CONFLICT, 'The task tree changed meanwhile: try again.');
    }
}
