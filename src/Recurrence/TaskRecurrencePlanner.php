<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence;

use DateTimeImmutable;

use function array_values;
use function count;
use function in_array;
use function max;
use function min;
use function sort;

/**
 * Since 2.3.0 (spec 215, FR-002): the dates of a series. Pure: no clock and no database, so every case is a table test.
 */
final class TaskRecurrencePlanner
{
    /** No series looks further than this many periods ahead (a guard against rules that never match). */
    private const MAX_STEPS = 5000;

    /**
     * The next `$count` dates of a fixed series strictly after `$after`, skipping `$skipped` (Y-m-d), and stopping at the
     * end condition. `$created` is how many occurrences exist already (the first task counts), for `endCount`.
     *
     * @param list<string> $skipped
     *
     * @return list<DateTimeImmutable>
     */
    public function nextDates(TaskRecurrenceRule $rule, ?DateTimeImmutable $after, int $count, array $skipped = [], int $created = 1): array
    {
        if ($rule->mode !== TaskRecurrenceRule::MODE_FIXED || $count <= 0) {
            return [];
        }
        $remaining = $rule->endCount !== null ? max(0, $rule->endCount - $created) : null;
        $after     = $after?->setTime(0, 0);
        $dates     = [];
        $steps     = 0;
        foreach ($this->sequence($rule) as $date) {
            if (++$steps > self::MAX_STEPS || ($rule->endDate instanceof DateTimeImmutable && $date > $rule->endDate->setTime(0, 0))) {
                break;
            }
            if ($after instanceof DateTimeImmutable && $date <= $after) {
                continue;
            }
            if (in_array($date->format('Y-m-d'), $skipped, true)) {
                continue;
            }
            // A skipped date is not an occurrence, so it does not use up an end after N occurrences.
            if ($remaining !== null && $remaining-- <= 0) {
                break;
            }
            $dates[] = $date;
            if (count($dates) >= $count) {
                break;
            }
        }

        return $dates;
    }

    /**
     * The date of the next occurrence of an after-completion series completed on `$completedAt`, `$periods` periods later
     * (2 when the next one was skipped); null past the end date.
     */
    public function afterCompletion(TaskRecurrenceRule $rule, DateTimeImmutable $completedAt, int $periods = 1): ?DateTimeImmutable
    {
        $date = $this->add($completedAt->setTime(0, 0), $rule->unit, $rule->interval * $periods, $completedAt->format('j'));

        return $rule->endDate instanceof DateTimeImmutable && $date > $rule->endDate->setTime(0, 0) ? null : $date;
    }

    /**
     * Every date of the rule from its anchor, in order.
     *
     * @return iterable<DateTimeImmutable>
     */
    private function sequence(TaskRecurrenceRule $rule): iterable
    {
        $anchor   = $rule->anchor->setTime(0, 0);
        $interval = max(1, $rule->interval);
        if ($rule->unit === 'week') {
            $days = $rule->weekdays !== [] ? array_values($rule->weekdays) : [(int) $anchor->format('N')];
            sort($days);
            $monday = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
            for ($week = 0;; $week += $interval) {
                foreach ($days as $day) {
                    $date = $monday->modify('+' . ($week * 7 + $day - 1) . ' days');
                    if ($date >= $anchor) {
                        yield $date;
                    }
                }
            }
        }
        $day = $rule->unit === 'month' && $rule->monthDay !== null ? $rule->monthDay : (int) $anchor->format('j');
        for ($step = 0;; $step += $interval) {
            $date = $this->add($anchor, $rule->unit, $step, (string) $day);
            if ($date >= $anchor || $rule->unit !== 'month') {
                yield $date;
            }
        }
    }

    /**
     * `$date` plus `$n` units. Months and years land on `$day` of the target month, clamped to its length (-1: last day).
     */
    private function add(DateTimeImmutable $date, string $unit, int $n, string|int $day): DateTimeImmutable
    {
        if ($unit === 'day' || $unit === 'week') {
            return $date->modify('+' . ($unit === 'week' ? 7 * $n : $n) . ' days');
        }
        $months = $unit === 'year' ? 12 * $n : $n;
        $first  = $date->modify('first day of this month')->modify('+' . $months . ' months');
        $length = (int) $first->format('t');
        $target = (int) $day === TaskRecurrenceRule::LAST_DAY ? $length : min((int) $day, $length);

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $target);
    }
}
