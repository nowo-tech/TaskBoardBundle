<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use RuntimeException;

/**
 * Since 2.29.0: a {@see TaskCommandsInterface} call was refused because the acting user is unknown, the board, task or
 * other resource does not exist, or the user may not do this. One answer for all of them, with the same message, so an id
 * never confirms that a user or a resource exists.
 */
final class TaskBoardAccessDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('TaskBoard: not found or not allowed.');
    }
}
