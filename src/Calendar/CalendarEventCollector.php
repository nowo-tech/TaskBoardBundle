<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeInterface;
use DateTimeZone;
use Nowo\TaskBoardBundle\Dto\TaskCalendarEventEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function date_default_timezone_get;
use function get_debug_type;
use function in_array;
use function preg_match;
use function usort;

/**
 * Since 2.24.0: asks every calendar event source (tag `nowo_task_board.calendar_event_source`) for the events of a calendar
 * page, once per source, and keeps only what is safe to draw: {@see CalendarEvent} values that overlap the page, of the
 * viewer's tenant (byte for byte), one per source and id, at most `calendar.events.max_per_source` per source. A source
 * that fails, or whose key is invalid or taken by an earlier source, is left out and logged; the page still renders.
 *
 * Stateless: the sources are services, everything else lives in the call (FrankenPHP worker mode, REQ-RUNTIME-002).
 *
 * @internal
 */
final readonly class CalendarEventCollector
{
    public const TAG                    = 'nowo_task_board.calendar_event_source';
    public const DEFAULT_MAX_PER_SOURCE = 500;

    /**
     * @param iterable<CalendarEventSourceInterface> $sources
     * @param string|null $timezone `calendar.events.timezone`; null: the PHP default timezone, read per call
     */
    public function __construct(
        private iterable $sources,
        private LoggerInterface $logger = new NullLogger(),
        private ?string $timezone = null,
        private int $maxPerSource = self::DEFAULT_MAX_PER_SOURCE,
    ) {
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone ?? date_default_timezone_get());
    }

    /**
     * The events of the page, sorted by day, all-day first, then time, source and id.
     *
     * @return list<TaskCalendarEventEntry>
     */
    public function collect(CalendarViewer $viewer, CalendarDateRange $days): array
    {
        $zone    = $this->timezone();
        $range   = new CalendarEventRange($days, $zone->getName());
        $entries = [];
        foreach ($this->validSources() as [$key, $source]) {
            $movable = $source instanceof CalendarEventReschedulerInterface;
            $seen    = [];
            $dropped = 0;

            try {
                foreach ($source->events($viewer, $range) as $event) {
                    if (!$event instanceof CalendarEvent || $event->tenantKey !== $viewer->tenantKey || !$range->overlaps($event) || isset($seen["\0" . $event->id])) {
                        ++$dropped;

                        continue;
                    }
                    if (count($seen) >= $this->maxPerSource) {
                        $this->logger->warning('TaskBoard calendar event source {source} returned more than {max} events; the rest are not shown', ['bundle' => 'nowo_task_board', 'source' => $key, 'max' => $this->maxPerSource]);

                        break;
                    }
                    $seen["\0" . $event->id] = true;
                    $entries[]               = $this->entry($key, $event, $zone, $movable && !$event->readOnly);
                }
            } catch (Throwable $exception) {
                // A failing source never breaks the calendar: its events are left out (as a failing day annotation provider).
                $this->logger->warning('TaskBoard calendar event source {source} failed: {message}', ['bundle' => 'nowo_task_board', 'source' => $key, 'exception' => $exception, 'message' => $exception->getMessage()]);

                continue;
            }
            if ($dropped > 0) {
                $this->logger->notice('TaskBoard calendar event source {source}: {count} events dropped (not an event, another tenant, out of range or a repeated id)', ['bundle' => 'nowo_task_board', 'source' => $key, 'count' => $dropped]);
            }
        }

        usort($entries, static fn (TaskCalendarEventEntry $a, TaskCalendarEventEntry $b): int => [$a->first, !$a->event->allDay, $a->event->start->getTimestamp(), $a->source, $a->event->id] <=> [$b->first, !$b->event->allDay, $b->event->start->getTimestamp(), $b->source, $b->event->id]);

        return $entries;
    }

    /** The source of that key (compared byte for byte), when it can reschedule; null otherwise (unknown key, read-only source). */
    public function rescheduler(string $key): ?CalendarEventReschedulerInterface
    {
        foreach ($this->validSources() as [$sourceKey, $source]) {
            if ($sourceKey === $key) {
                return $source instanceof CalendarEventReschedulerInterface ? $source : null;
            }
        }

        return null;
    }

    /**
     * The sources and their keys: a key that is not valid, or that an earlier source already has, is left out (and logged).
     *
     * @return list<array{string, CalendarEventSourceInterface}>
     */
    private function validSources(): array
    {
        $sources = [];
        $keys    = [];
        foreach ($this->sources as $source) {
            $key = $source->key();
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $key) !== 1 || in_array($key, $keys, true)) {
                $this->logger->error('TaskBoard calendar event source {class} has an invalid or repeated key; it is ignored', ['bundle' => 'nowo_task_board', 'class' => get_debug_type($source)]);

                continue;
            }
            $keys[]    = $key;
            $sources[] = [$key, $source];
        }

        return $sources;
    }

    private function entry(string $key, CalendarEvent $event, DateTimeZone $zone, bool $movable): TaskCalendarEventEntry
    {
        if ($event->allDay) {
            return new TaskCalendarEventEntry($key, $event, $event->firstDay(), $event->lastDay(), null, null, $event->firstDay(), $event->lastDay(), $movable);
        }

        $start = $event->start->setTimezone($zone);
        $end   = $event->end->setTimezone($zone);
        // An end at midnight closes the day before (a meeting until 00:00 is not on the next day).
        $last = $end > $start && $end->format('H:i:s') === '00:00:00' ? $end->modify('-1 day') : $end;

        return new TaskCalendarEventEntry(
            $key,
            $event,
            $start->format('Y-m-d'),
            $last->format('Y-m-d'),
            $start->format('H:i'),
            $end->format('H:i'),
            $start->format(DateTimeInterface::ATOM),
            $end->format(DateTimeInterface::ATOM),
            $movable,
        );
    }
}
