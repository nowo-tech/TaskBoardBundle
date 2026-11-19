<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Since 2.24.0: what a calendar page asks a {@see CalendarEventSourceInterface} for: the days it shows (`days`, both included)
 * and the same span as instants, `from` (midnight of the first day) included and `to` (midnight after the last day) excluded,
 * in the calendar's timezone (`calendar.events.timezone`, the PHP default when not set).
 */
final readonly class CalendarEventRange
{
    public DateTimeImmutable $from;

    public DateTimeImmutable $to;

    public function __construct(
        public CalendarDateRange $days,
        public string $timezone,
    ) {
        $zone       = new DateTimeZone($timezone);
        $this->from = new DateTimeImmutable($days->from . ' 00:00:00', $zone);
        $this->to   = (new DateTimeImmutable($days->to . ' 00:00:00', $zone))->modify('+1 day');
    }

    /** Whether the event is on one of the days: an all-day event by its days, a timed one by its instants. */
    public function overlaps(CalendarEvent $event): bool
    {
        if ($event->allDay) {
            return $event->firstDay() <= $this->days->to && $event->lastDay() >= $this->days->from;
        }

        // An event of no duration is on the day of its start.
        return $event->start < $this->to && ($event->end > $this->from || $event->start >= $this->from);
    }
}
