<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function sprintf;

/**
 * Since 2.24.0: a move of one event to another day, as {@see CalendarEventReschedulerInterface::reschedule()} gets it.
 *
 * - `eventId`: the event's id, as the source returned it (look it up within the viewer's tenant).
 * - `expectedStart` / `expectedEnd` / `expectedVersion`: what the calendar showed (optimistic concurrency). Compare them
 *   with what is stored, as instants (`==`, seconds precision; an all-day event by its dates), and answer `conflict()`
 *   without writing when they differ.
 * - `start` / `end`: the new values. The calendar moves an event by whole days: a timed event keeps its time of day in the
 *   calendar's timezone and its duration in seconds (across a daylight-saving change too); an all-day event keeps its
 *   number of days. Both are in the calendar's timezone.
 */
final readonly class CalendarEventReschedule
{
    public function __construct(
        public string $eventId,
        public DateTimeImmutable $expectedStart,
        public DateTimeImmutable $expectedEnd,
        public ?string $expectedVersion,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public bool $allDay,
    ) {
    }

    /**
     * The move of the event shown from `expectedStart` to `expectedEnd` so that its first day is `day` (`Y-m-d`).
     *
     * @throws InvalidArgumentException when `day` is not a Y-m-d date or the event ends before it starts
     */
    public static function toDay(string $eventId, DateTimeImmutable $expectedStart, DateTimeImmutable $expectedEnd, ?string $expectedVersion, bool $allDay, string $day, DateTimeZone $timezone): self
    {
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $timezone);
        if (!$target instanceof DateTimeImmutable || $target->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException(sprintf('A calendar event moves to a Y-m-d day, "%s" given.', $day));
        }
        if ($allDay) {
            $first = new DateTimeImmutable($expectedStart->format('Y-m-d'), $timezone);
            $last  = new DateTimeImmutable($expectedEnd->format('Y-m-d'), $timezone);
            $days  = (int) $first->diff($last)->format('%r%a');
            if ($days < 0) {
                throw new InvalidArgumentException('A calendar event ends on or after its start.');
            }

            return new self($eventId, $expectedStart, $expectedEnd, $expectedVersion, $target, $target->modify('+' . $days . ' days'), true);
        }

        $seconds = $expectedEnd->getTimestamp() - $expectedStart->getTimestamp();
        if ($seconds < 0) {
            throw new InvalidArgumentException('A calendar event ends on or after its start.');
        }
        $local = $expectedStart->setTimezone($timezone);
        $start = $target->setTime((int) $local->format('G'), (int) $local->format('i'), (int) $local->format('s'));
        $end   = $start->modify('+' . $seconds . ' seconds');

        return new self($eventId, $expectedStart, $expectedEnd, $expectedVersion, $start, $end, false);
    }
}
