<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.14.0: an assignee is off on one of the task's days (its due date, its start or a day between, within the page).
 */
final readonly class CalendarTaskWarning
{
    public function __construct(
        public string $assigneeId,
        public CalendarDayAnnotation $day,
    ) {
    }
}
