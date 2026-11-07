<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use InvalidArgumentException;

/**
 * Since 2.1.0 (spec 214, FR-026): a task's start date cannot be after its due date.
 */
final class InvalidTaskScheduleException extends InvalidArgumentException
{
}
