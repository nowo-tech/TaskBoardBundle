<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

use Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface;

/**
 * Since 1.15: the first day of the week of the Gantt (`nowo_task_board.gantt.week_start`, or the host's
 * {@see WeekStartResolverInterface}): the Weeks scale columns and the week separators.
 */
enum WeekStart: string
{
    case Monday = 'monday';
    case Sunday = 'sunday';

    /** ISO-8601 day number (`N` date format): 1 for Monday, 7 for Sunday. */
    public function isoDay(): int
    {
        return $this === self::Monday ? 1 : 7;
    }
}
