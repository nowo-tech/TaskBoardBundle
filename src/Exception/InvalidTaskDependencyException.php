<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use InvalidArgumentException;

/**
 * Since 1.15: a dependency the board refuses — on the task itself, to a task of another board, a duplicate, or one
 * that would close a cycle of "blocks" links. `reason` is one of the REASON_* constants (the flash message key suffix).
 */
final class InvalidTaskDependencyException extends InvalidArgumentException
{
    public const REASON_SELF        = 'self';
    public const REASON_OTHER_BOARD = 'other_board';
    public const REASON_DUPLICATE   = 'duplicate';
    public const REASON_CYCLE       = 'cycle';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function self(): self
    {
        return new self(self::REASON_SELF, 'A task cannot depend on itself.');
    }

    public static function otherBoard(): self
    {
        return new self(self::REASON_OTHER_BOARD, 'A task can only depend on a task of the same board.');
    }

    public static function duplicate(): self
    {
        return new self(self::REASON_DUPLICATE, 'The tasks already have this dependency.');
    }

    public static function cycle(): self
    {
        return new self(self::REASON_CYCLE, 'The dependency would create a cycle.');
    }
}
