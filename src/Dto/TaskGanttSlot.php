<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Since 1.15: one column of the Gantt timeline in its scale (an hour, a day, a week or a month).
 */
final readonly class TaskGanttSlot
{
    public function __construct(
        // Unique key: Y-m-d\TH (hours), Y-m-d (days, and the first day of a week), Y-m (months).
        public string $key,
        // First and last day the column covers (Y-m-d).
        public string $date,
        public string $lastDate,
        // Hour of an hours column, null otherwise.
        public ?int $hour,
        // ISO-8601 weekday (1 = Monday … 7 = Sunday), day of the month, month (1–12) and year of `date`.
        public int $weekday,
        public int $day,
        public int $month,
        public int $year,
        // An hour or a day on a Saturday or Sunday (weeks and months are never shaded).
        public bool $weekend,
        // The column holding now.
        public bool $today,
        // First column of a header group: a day (hours), a month (days and weeks) or a year (months).
        public bool $groupStart,
        // First column of a week (days, by the week start) or of a day (hours): a stronger separator.
        public bool $periodStart,
    ) {
    }
}
