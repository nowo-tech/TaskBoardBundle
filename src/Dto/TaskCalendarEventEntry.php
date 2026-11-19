<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Calendar\CalendarEvent;

/**
 * Since 2.24.0: an event of a calendar event source as the page draws it, in the calendar's timezone: the days it covers
 * (`first` to `last`, `Y-m-d`), its times (`H:i`, null for an all-day event) and the values a move sends back.
 */
final readonly class TaskCalendarEventEntry
{
    public function __construct(
        public string $source,
        public CalendarEvent $event,
        public string $first,
        public string $last,
        // `H:i` in the calendar's timezone; null for an all-day event.
        public ?string $startTime,
        public ?string $endTime,
        // What a move sends back as expected values: ISO 8601 with offset (`Y-m-d` for an all-day event).
        public string $startValue,
        public string $endValue,
        // Whether the viewer may try to move it (not read-only, and the source can reschedule).
        public bool $movable,
    ) {
    }

    /** Whether the event goes on beyond the day (a timed event that ends on a later day, or an all-day one of several days). */
    public function spansSeveralDays(): bool
    {
        return $this->first !== $this->last;
    }
}
