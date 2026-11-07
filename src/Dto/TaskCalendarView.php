<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\CalendarMode;

/**
 * Since 2.1.0 (spec 214): what the calendar page draws. `weeks` are rows of seven days (month, week); `days` are the days with
 * tasks, in order (list).
 */
final readonly class TaskCalendarView
{
    /**
     * @param list<list<TaskCalendarDay>> $weeks
     * @param list<TaskCalendarDay> $days
     * @param list<int> $weekdays ISO weekdays, from the first day of the week
     */
    public function __construct(
        public CalendarMode $mode,
        public string $date,
        public string $previous,
        public string $next,
        public string $today,
        public string $firstDay,
        public string $lastDay,
        public array $weeks,
        public array $days,
        public array $weekdays,
        public int $placed,
        public bool $anyEditable,
    ) {
    }
}
