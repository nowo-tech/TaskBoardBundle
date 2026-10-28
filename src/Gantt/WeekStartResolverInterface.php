<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Gantt;

use Nowo\TaskBoardBundle\Enum\WeekStart;

/**
 * Since 1.15: the first day of the week for the current request's Gantt. The default
 * ({@see ConfiguredWeekStartResolver}) answers `nowo_task_board.gantt.week_start`; a host answers per user (e.g. a
 * profile preference) with its own service set as `nowo_task_board.gantt.week_start_resolver`.
 */
interface WeekStartResolverInterface
{
    public function resolve(): WeekStart;
}
