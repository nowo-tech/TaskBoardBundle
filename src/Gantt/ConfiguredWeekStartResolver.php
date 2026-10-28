<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Gantt;

use Nowo\TaskBoardBundle\Enum\WeekStart;

/**
 * Since 1.15: the configured first day of the week (`nowo_task_board.gantt.week_start`, default monday).
 */
final readonly class ConfiguredWeekStartResolver implements WeekStartResolverInterface
{
    public function __construct(
        private string $weekStart = 'monday',
    ) {
    }

    public function resolve(): WeekStart
    {
        return WeekStart::tryFrom($this->weekStart) ?? WeekStart::Monday;
    }
}
