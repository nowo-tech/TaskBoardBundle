<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;

/**
 * Since 2.24.0 (audit UW-TB-1): what a calendar page showed of an event when the viewer moved it, as the browser sent it
 * back, and the new first day. Only compared with the event the source returns again; never used to compute the move.
 *
 * @internal
 */
final readonly class CalendarEventShown
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public bool $allDay,
        public ?string $version,
        public string $date,
    ) {
    }

    /**
     * Whether the source's event is still what the page showed: the same all-day flag, version, and start and end (as
     * instants, to the second; an all-day event by its dates).
     */
    public function matches(CalendarEvent $event): bool
    {
        if ($event->allDay !== $this->allDay || $event->version !== $this->version) {
            return false;
        }

        return $this->allDay
            ? $event->firstDay() === $this->start->format('Y-m-d') && $event->lastDay() === $this->end->format('Y-m-d')
            : $event->start->getTimestamp() === $this->start->getTimestamp() && $event->end->getTimestamp() === $this->end->getTimestamp();
    }
}
