<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskCalendarChip;
use Nowo\TaskBoardBundle\Dto\TaskCalendarDay;
use Nowo\TaskBoardBundle\Dto\TaskCalendarEntry;
use Nowo\TaskBoardBundle\Dto\TaskCalendarView;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\CalendarMode;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_chunk;
use function array_keys;
use function array_map;
use function count;
use function in_array;
use function is_string;
use function max;
use function min;
use function usort;

/**
 * Since 2.1.0 (spec 214, FR-018 to FR-020): the days of a calendar and where each task goes: start and due are a bar across the
 * days, one date is that day, no date is not on the calendar. Only days: a time of day is never shown.
 */
final readonly class TaskCalendarBuilder
{
    /** Bars per week in the month grid; the rest of a day is a "+n more" link. */
    private const MONTH_LANES = 4;

    /** Far enough for any plan; a URL cannot ask for a page around year 9999. */
    private const MIN_YEAR = 1970;
    private const MAX_YEAR = 2200;

    public function __construct(
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /** The day of `?date=`; anything but a real Y-m-d in range is today. */
    public function day(mixed $value): DateTimeImmutable
    {
        if (is_string($value)) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($day instanceof DateTimeImmutable && $day->format('Y-m-d') === $value && $this->isInRange($day)) {
                return $day;
            }
        }

        return $this->clock->now()->setTime(0, 0);
    }

    /** Whether the calendar can show the day, so whether a task may be scheduled on it (audit HY-L1). */
    public function isInRange(DateTimeImmutable $day): bool
    {
        $year = (int) $day->format('Y');

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    /**
     * The first day shown and the day after the last one.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function range(CalendarMode $mode, DateTimeImmutable $date, WeekStart $weekStart): array
    {
        $day = $date->setTime(0, 0);

        return match ($mode) {
            CalendarMode::Week  => [$this->weekOf($day, $weekStart), $this->weekOf($day, $weekStart)->modify('+7 days')],
            CalendarMode::List  => [$day->modify('first day of this month'), $day->modify('first day of next month')],
            CalendarMode::Month => [
                $this->weekOf($day->modify('first day of this month'), $weekStart),
                $this->weekOf($day->modify('last day of this month'), $weekStart)->modify('+7 days'),
            ],
        };
    }

    /**
     * @param list<Task> $tasks the dated tasks overlapping the range
     * @param array<string, bool> $editable task id => whether the user may change it
     */
    public function build(CalendarMode $mode, DateTimeImmutable $date, WeekStart $weekStart, array $tasks, array $editable = []): TaskCalendarView
    {
        $today       = $this->clock->now()->format('Y-m-d');
        [$from, $to] = $this->range($mode, $date, $weekStart);
        $period      = $mode === CalendarMode::Month ? $date->format('Y-m') : null;

        $entries = [];
        foreach ($tasks as $task) {
            $entry = $this->entry($task, $today, $editable[$task->getId()] ?? false);
            if ($entry instanceof TaskCalendarEntry) {
                $entries[] = $entry;
            }
        }

        /** @var list<string> $dates */
        $dates = [];
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        $weeks = $mode === CalendarMode::List ? [] : array_map(
            fn (array $week): array => $this->week($week, $entries, $mode === CalendarMode::Month ? self::MONTH_LANES : null, $today, $period),
            array_chunk($dates, 7),
        );

        $days = [];
        if ($mode === CalendarMode::List) {
            foreach ($dates as $key) {
                $covering = $this->covering($entries, $key);
                if ($covering !== []) {
                    $days[] = $this->calendarDay($key, [], $covering, 0, $today, null);
                }
            }
        }

        $step = $mode === CalendarMode::Week ? '7 days' : '1 month';
        $base = $mode === CalendarMode::Week ? $date : $date->modify('first day of this month');

        return new TaskCalendarView(
            mode: $mode,
            date: $date->format('Y-m-d'),
            previous: $base->modify('-' . $step)->format('Y-m-d'),
            next: $base->modify('+' . $step)->format('Y-m-d'),
            today: $today,
            firstDay: $dates[0],
            lastDay: $dates[count($dates) - 1],
            weeks: $weeks,
            days: $days,
            weekdays: array_map(static fn (int $offset): int => ($weekStart->isoDay() - 1 + $offset) % 7 + 1, [0, 1, 2, 3, 4, 5, 6]),
            placed: count($entries),
            anyEditable: in_array(true, array_map(static fn (TaskCalendarEntry $entry): bool => $entry->editable, $entries), true),
        );
    }

    private function entry(Task $task, string $today, bool $editable): ?TaskCalendarEntry
    {
        $start = $task->getStartAt()?->format('Y-m-d');
        $due   = $task->getDueAt()?->format('Y-m-d');
        $first = $start ?? $due;
        $last  = $due ?? $start;
        if ($first === null || $last === null) {
            return null;
        }

        return new TaskCalendarEntry(
            task: $task,
            start: $start,
            due: $due,
            first: $first,
            // Older data may have a due date before the start: it draws as one day.
            last: max($first, $last),
            overdue: $due !== null && $due < $today && !$task->isCompleted(),
            editable: $editable,
        );
    }

    private function weekOf(DateTimeImmutable $day, WeekStart $weekStart): DateTimeImmutable
    {
        return $day->modify('-' . (((int) $day->format('N') - $weekStart->isoDay() + 7) % 7) . ' days');
    }

    /**
     * @param list<string> $dates the seven days of the week
     * @param list<TaskCalendarEntry> $entries
     *
     * @return list<TaskCalendarDay>
     */
    private function week(array $dates, array $entries, ?int $maxLanes, string $today, ?string $period): array
    {
        $segments = [];
        foreach ($entries as $entry) {
            if ($entry->last < $dates[0] || $entry->first > $dates[6]) {
                continue;
            }
            $firstDay   = max($entry->first, $dates[0]);
            $lastDay    = min($entry->last, $dates[6]);
            $segments[] = [
                'entry' => $entry,
                'from'  => (int) array_keys($dates, $firstDay, true)[0],
                'to'    => (int) array_keys($dates, $lastDay, true)[0],
            ];
        }
        // Earlier bars first, the longest of a day on top, so the lanes stay tidy.
        usort($segments, static fn (array $a, array $b): int => [$a['from'], $b['to'], $a['entry']->task->getPosition(), $a['entry']->task->getId()] <=> [$b['from'], $a['to'], $b['entry']->task->getPosition(), $b['entry']->task->getId()]);

        /** @var array<int, array<int, true>> $lanes lane => taken day indexes */
        $lanes = [];
        /** @var array<int, list<TaskCalendarChip>> $chips */
        $chips = [];
        /** @var array<int, int> $more */
        $more = [];
        foreach ($segments as $segment) {
            $lane = $this->freeLane($lanes, $segment['from'], $segment['to']);
            if ($maxLanes !== null && $lane >= $maxLanes) {
                for ($index = $segment['from']; $index <= $segment['to']; ++$index) {
                    $more[$index] = ($more[$index] ?? 0) + 1;
                }

                continue;
            }
            for ($index = $segment['from']; $index <= $segment['to']; ++$index) {
                $lanes[$lane][$index] = true;
            }
            $entry                     = $segment['entry'];
            $chips[$segment['from']][] = new TaskCalendarChip($entry, $segment['to'] - $segment['from'] + 1, $lane + 1, $entry->first === $dates[$segment['from']], $entry->last === $dates[$segment['to']]);
        }

        $days = [];
        foreach ($dates as $index => $key) {
            $days[] = $this->calendarDay($key, $chips[$index] ?? [], $this->covering($entries, $key), $more[$index] ?? 0, $today, $period);
        }

        return $days;
    }

    /**
     * @param array<int, array<int, true>> $lanes
     */
    private function freeLane(array $lanes, int $from, int $to): int
    {
        for ($lane = 0;; ++$lane) {
            $free = true;
            foreach (array_keys($lanes[$lane] ?? []) as $taken) {
                if ($taken >= $from && $taken <= $to) {
                    $free = false;

                    break;
                }
            }
            if ($free) {
                return $lane;
            }
        }
    }

    /**
     * @param list<TaskCalendarEntry> $entries
     *
     * @return list<TaskCalendarEntry>
     */
    private function covering(array $entries, string $date): array
    {
        $covering = [];
        foreach ($entries as $entry) {
            if ($entry->first <= $date && $entry->last >= $date) {
                $covering[] = $entry;
            }
        }

        return $covering;
    }

    /**
     * @param list<TaskCalendarChip> $chips
     * @param list<TaskCalendarEntry> $entries
     */
    private function calendarDay(string $key, array $chips, array $entries, int $more, string $today, ?string $period): TaskCalendarDay
    {
        $day = new DateTimeImmutable($key);

        return new TaskCalendarDay(
            date: $key,
            day: (int) $day->format('j'),
            month: (int) $day->format('n'),
            weekday: (int) $day->format('N'),
            inPeriod: $period === null || $day->format('Y-m') === $period,
            today: $key === $today,
            weekend: (int) $day->format('N') >= 6,
            chips: $chips,
            entries: $entries,
            more: $more,
        );
    }
}
