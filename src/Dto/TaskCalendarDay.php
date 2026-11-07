<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Since 2.1.0 (spec 214): one day of the calendar.
 */
final readonly class TaskCalendarDay
{
    /**
     * @param list<TaskCalendarChip> $chips the bars that start in this cell
     * @param list<TaskCalendarEntry> $entries every task covering the day (the phone and list views)
     */
    public function __construct(
        public string $date,
        public int $day,
        public int $month,
        public int $weekday,
        public bool $inPeriod,
        public bool $today,
        public bool $weekend,
        public array $chips,
        public array $entries,
        // Tasks of the day with no room in the month grid.
        public int $more,
    ) {
    }
}
