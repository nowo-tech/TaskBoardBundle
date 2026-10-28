<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * The assignee provider refused a user as a member of a task of a board.
 */
final class UnassignableUserException extends InvalidArgumentException
{
    /**
     * The user may not be a member of the tasks of `$board`.
     */
    public static function forBoard(TaskBoard $board): self
    {
        return new self('The user may not be a member of the tasks of board "' . $board->getId() . '".');
    }
}
