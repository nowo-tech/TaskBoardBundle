<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use Nowo\TaskBoardBundle\Service\TaskTimePeriodLockInterface;
use RuntimeException;

/**
 * Since 2.12.0: a change of a manual time entry's `billable` flag was refused. The message is the stable code:
 * `not_found` (the task has no such entry), `forbidden` (neither its author nor a manager of the board, or no edit
 * right on the task), `anonymised` (the entry's user was deleted: it never changes), `period_locked` (its period is
 * locked or waiting for approval, {@see TaskTimePeriodLockInterface}).
 */
final class TaskTimeBillableRefusedException extends RuntimeException
{
    public const NOT_FOUND     = 'not_found';
    public const FORBIDDEN     = 'forbidden';
    public const ANONYMISED    = 'anonymised';
    public const PERIOD_LOCKED = 'period_locked';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
