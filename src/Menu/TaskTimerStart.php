<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Menu;

/**
 * Since 2.22.0: the outcome of "Start timer" in the task menu ({@see TaskTimerStarterInterface}).
 */
enum TaskTimerStart: string
{
    case Started = 'started';

    /** The user's timer already runs (on this or another task): nothing changed. */
    case AlreadyRunning = 'already_running';

    /** The time tracker refused the task (not trackable for the user, a locked period…): nothing changed. */
    case Refused = 'refused';
}
