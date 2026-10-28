<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskGanttItem;
use Nowo\TaskBoardBundle\Dto\TaskGanttLink;
use Nowo\TaskBoardBundle\Dto\TaskGanttSlot;
use Nowo\TaskBoardBundle\Dto\TaskGanttTimeline;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Support\TaskDeviation;

use function abs;
use function array_map;
use function count;
use function intdiv;
use function max;
use function min;

/**
 * Builds Gantt timeline data from board tasks.
 *
 * Since 1.15 the columns follow a {@see GanttScale} (hours, days, weeks or months) and the week start; each scale draws
 * between its minimum and maximum number of columns. A plan longer than the maximum is clamped to a window around
 * now (`TaskGanttTimeline::$clamped`): bars running past it are clipped, bars outside it are not drawn.
 */
final readonly class TaskGanttBuilder
{
    private const DEFAULT_BAR_DAYS = 1;
    private const MINUTES_PER_DAY  = 480;
    /** Since 1.13: at least three weeks are drawn, so short plans do not squeeze into a few columns. */
    public const MIN_RANGE_DAYS = 21;

    /**
     * @param list<Task> $tasks
     * @param DateTimeImmutable|null $now Since 1.15: the current time (default: now)
     */
    public function build(array $tasks, GanttScale $scale = GanttScale::Days, WeekStart $weekStart = WeekStart::Monday, ?DateTimeImmutable $now = null): TaskGanttTimeline
    {
        $now ??= new DateTimeImmutable();
        $flat = $this->flatten($tasks);
        if ($flat === []) {
            // No task, or (only with corrupted, cyclic parent data) no root to draw.
            return $this->timeline([], [], $scale, $weekStart, $now, $now, $now, false);
        }

        $today    = $now->setTime(0, 0);
        $entries  = [];
        $minStart = null;
        $maxEnd   = null;
        foreach ($flat as $entry) {
            $range     = $this->resolveRange($entry['task'], $today, $now, $scale);
            $minStart  = $minStart === null ? $range['start'] : min($minStart, $range['start']);
            $maxEnd    = $maxEnd === null ? $range['end'] : max($maxEnd, $range['end']);
            $entries[] = $entry + $range;
        }

        return $this->timeline($entries, $this->collectLinks($tasks), $scale, $weekStart, $now, $minStart, $maxEnd, true);
    }

    /**
     * @param list<array{task: Task, depth: int, start: DateTimeImmutable, end: DateTimeImmutable, startDay: DateTimeImmutable, endDay: DateTimeImmutable, timed: bool}> $entries
     * @param list<TaskGanttLink> $links
     */
    private function timeline(array $entries, array $links, GanttScale $scale, WeekStart $weekStart, DateTimeImmutable $now, DateTimeImmutable $minStart, DateTimeImmutable $maxEnd, bool $hasTasks): TaskGanttTimeline
    {
        $pad   = $hasTasks ? $this->padding($scale) : $this->emptyPadding($scale);
        $first = $this->step($scale, $this->align($scale, $weekStart, $minStart), -$pad);
        $last  = $hasTasks ? $this->step($scale, $this->align($scale, $weekStart, $maxEnd->modify('-1 second')), $pad) : $first;

        $count   = max($this->slotsBetween($scale, $first, $last) + 1, $scale->minSlots());
        $clamped = $count > $scale->maxSlots();
        if ($clamped) {
            $count  = $scale->maxSlots();
            $window = $this->step($scale, $this->align($scale, $weekStart, $now), -intdiv($count, 4));
            if ($window > $first) {
                // Keep the window inside the plan when now is near its end (or after it).
                $first = $this->slotsBetween($scale, $window, $last) + 1 < $count ? $this->step($scale, $last, 1 - $count) : $window;
            }
        }

        $slots      = [];
        $starts     = [];
        $todayIndex = null;
        $previous   = null;
        for ($i = 0; $i < $count; ++$i) {
            $start    = $this->step($scale, $first, $i);
            $next     = $this->step($scale, $first, $i + 1);
            $starts[] = $start->getTimestamp();
            $isToday  = $start <= $now && $now < $next;
            if ($isToday) {
                $todayIndex = $i;
            }

            $slots[]  = $this->slot($scale, $weekStart, $start, $next, $isToday, $previous);
            $previous = $start;
        }

        $end   = $this->step($scale, $first, $count);
        $items = [];
        foreach ($entries as $entry) {
            $items[] = $this->item($entry, $starts, $first, $end);
        }

        return new TaskGanttTimeline(
            items: $items,
            links: $links,
            rangeStart: $slots[0]->date,
            rangeEnd: $slots[$count - 1]->lastDate,
            dayKeys: array_map(static fn (TaskGanttSlot $slot): string => $slot->date, $slots),
            today: $now->format('Y-m-d'),
            slots: $slots,
            scale: $scale,
            weekStart: $weekStart,
            clamped: $clamped,
            todayIndex: $todayIndex,
        );
    }

    /**
     * @param array{task: Task, depth: int, start: DateTimeImmutable, end: DateTimeImmutable, startDay: DateTimeImmutable, endDay: DateTimeImmutable, timed: bool} $entry
     * @param list<int> $starts Slot start timestamps
     */
    private function item(array $entry, array $starts, DateTimeImmutable $first, DateTimeImmutable $end): TaskGanttItem
    {
        $task         = $entry['task'];
        $visible      = $entry['end'] > $first && $entry['start'] < $end;
        $clippedStart = $visible && $entry['start'] < $first;
        $clippedEnd   = $visible && $entry['end'] > $end;
        $startIndex   = $visible ? $this->indexOf($starts, $clippedStart ? $first : $entry['start']) : 0;
        $endIndex     = $visible ? $this->indexOf($starts, ($clippedEnd ? $end : $entry['end'])->modify('-1 second')) : 0;
        $deviation    = TaskDeviation::of($task);

        return new TaskGanttItem(
            id: $task->getId(),
            title: $task->getTitle(),
            startDate: $entry['startDay']->format('Y-m-d'),
            endDate: $entry['endDay']->format('Y-m-d'),
            startIndex: $startIndex,
            endIndex: max($startIndex, $endIndex),
            progress: $task->isCompleted() ? 100 : 0,
            priority: $task->getPriority()->value,
            columnName: $task->getColumn()?->getName(),
            depth: $entry['depth'],
            completed: $task->isCompleted(),
            color: $task->getColor(),
            estimatedMinutes: $task->getEstimatedMinutes(),
            loggedMinutes: $deviation->spentMinutes,
            spentPercent: $deviation->spentPercent(),
            deviationStatus: $deviation->status(),
            overrun: $deviation->isOver(),
            visible: $visible,
            clippedStart: $clippedStart,
            clippedEnd: $clippedEnd,
            startTime: $entry['timed'] ? $entry['start']->format('H:i') : null,
            endTime: $entry['timed'] ? $entry['end']->format('H:i') : null,
        );
    }

    /**
     * The last slot starting at or before `$at` (the slots are sorted).
     *
     * @param list<int> $starts
     */
    private function indexOf(array $starts, DateTimeImmutable $at): int
    {
        $timestamp = $at->getTimestamp();
        $low       = 0;
        $high      = count($starts) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($starts[$middle] <= $timestamp) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    private function slot(GanttScale $scale, WeekStart $weekStart, DateTimeImmutable $start, DateTimeImmutable $next, bool $isToday, ?DateTimeImmutable $previous): TaskGanttSlot
    {
        $weekday = (int) $start->format('N');
        $hour    = (int) $start->format('G');
        $day     = (int) $start->format('j');
        $month   = (int) $start->format('n');

        return new TaskGanttSlot(
            key: $start->format(match ($scale) {
                GanttScale::Hours  => 'Y-m-d\TH',
                GanttScale::Months => 'Y-m',
                default            => 'Y-m-d',
            }),
            date: $start->format('Y-m-d'),
            lastDate: $next->modify('-1 second')->format('Y-m-d'),
            hour: $scale === GanttScale::Hours ? $hour : null,
            weekday: $weekday,
            day: $day,
            month: $month,
            year: (int) $start->format('Y'),
            weekend: ($scale === GanttScale::Hours || $scale === GanttScale::Days) && $weekday >= 6,
            today: $isToday,
            groupStart: !$previous instanceof DateTimeImmutable || match ($scale) {
                GanttScale::Hours  => $hour === 0,
                GanttScale::Days   => $day === 1,
                GanttScale::Weeks  => $previous->format('n') !== $start->format('n'),
                GanttScale::Months => $month === 1,
            },
            periodStart: match ($scale) {
                GanttScale::Hours => $hour === 0,
                GanttScale::Days  => $weekday === $weekStart->isoDay(),
                default           => false,
            },
        );
    }

    /**
     * The tasks in tree order (roots by position, each followed by its subtasks) with their depth.
     *
     * @param list<Task> $tasks
     *
     * @return list<array{task: Task, depth: int}>
     */
    private function flatten(array $tasks): array
    {
        /** @var array<string, list<Task>> $childrenByParent */
        $childrenByParent = [];
        /** @var list<Task> $roots */
        $roots = [];

        $taskIds = [];
        foreach ($tasks as $task) {
            $taskIds[$task->getId()] = true;
        }

        foreach ($tasks as $task) {
            $parent = $task->getParent();
            // Subtasks whose parent is not part of the timeline are drawn at root level instead of vanishing.
            if ($parent === null || !isset($taskIds[$parent->getId()])) {
                $roots[] = $task;

                continue;
            }

            $childrenByParent[$parent->getId()] ??= [];
            $childrenByParent[$parent->getId()][] = $task;
        }

        usort($roots, static fn (Task $a, Task $b): int => $a->getPosition() <=> $b->getPosition());
        foreach ($childrenByParent as &$children) {
            usort($children, static fn (Task $a, Task $b): int => $a->getPosition() <=> $b->getPosition());
        }
        unset($children);

        /** @var list<array{task: Task, depth: int}> $flat */
        $flat = [];
        foreach ($roots as $root) {
            $this->walkTree($root, $childrenByParent, $flat, 0);
        }

        return $flat;
    }

    /**
     * @param list<array{task: Task, depth: int}> $flat
     * @param array<string, list<Task>> $childrenByParent
     */
    private function walkTree(Task $task, array $childrenByParent, array &$flat, int $depth): void
    {
        $flat[] = ['task' => $task, 'depth' => $depth];

        foreach ($childrenByParent[$task->getId()] ?? [] as $child) {
            $this->walkTree($child, $childrenByParent, $flat, $depth + 1);
        }
    }

    /**
     * The bar of a task: its days (start and end inclusive) and its instants (start inclusive, end exclusive). Whole
     * days, except in the hours scale for a task whose due date carries a time.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, startDay: DateTimeImmutable, endDay: DateTimeImmutable, timed: bool}
     */
    private function resolveRange(Task $task, DateTimeImmutable $today, DateTimeImmutable $now, GanttScale $scale): array
    {
        [$startDay, $endDay] = $this->resolveDateRange($task, $today);
        $dueAt               = $task->getDueAt();

        if ($scale === GanttScale::Hours && $dueAt instanceof DateTimeImmutable && $dueAt->format('H:i:s') !== '00:00:00') {
            $end         = $dueAt;
            $completedAt = $task->getCompletedAt();
            if ($task->isCompleted() && $completedAt instanceof DateTimeImmutable && $completedAt < $end) {
                $end = $completedAt;
            }

            if (!$task->isCompleted() && $end < $now) {
                $end = $now;
            }

            // Since 1.15: the start date when set (else the creation), from its hour.
            $created = $task->getPlannedStart();
            $start   = $created->setTime((int) $created->format('G'), 0);
            if ($start >= $end) {
                $start = $end->modify('-1 hour');
            }

            return ['start' => $start, 'end' => $end, 'startDay' => $start->setTime(0, 0), 'endDay' => $end->setTime(0, 0), 'timed' => true];
        }

        return ['start' => $startDay, 'end' => $endDay->modify('+1 day'), 'startDay' => $startDay, 'endDay' => $endDay, 'timed' => false];
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function resolveDateRange(Task $task, DateTimeImmutable $today): array
    {
        // Since 1.15: the start date when set, else the creation day.
        $start = $task->getPlannedStart()->setTime(0, 0);
        $end   = $task->getDueAt()?->setTime(0, 0);

        if (!$end instanceof DateTimeImmutable && $task->getEstimatedMinutes() !== null && $task->getEstimatedMinutes() > 0) {
            $durationDays = max(self::DEFAULT_BAR_DAYS, (int) ceil($task->getEstimatedMinutes() / self::MINUTES_PER_DAY));
            $end          = $start->modify('+' . ($durationDays - 1) . ' days');
        }

        if (!$end instanceof DateTimeImmutable) {
            $end = $start->modify('+' . (self::DEFAULT_BAR_DAYS - 1) . ' days');
        }

        $completedAt = $task->getCompletedAt();
        if ($task->isCompleted() && $completedAt instanceof DateTimeImmutable && $completedAt->setTime(0, 0) < $end) {
            $end = $completedAt->setTime(0, 0);
        }

        if ($end < $start) {
            $end = $start;
        }

        if ($start < $today && !$task->isCompleted() && $end < $today) {
            $end = $today;
        }

        return [$start, $end];
    }

    /** Columns added before the first and after the last bar. */
    private function padding(GanttScale $scale): int
    {
        return match ($scale) {
            GanttScale::Hours => 0,
            GanttScale::Days  => 2,
            default           => 1,
        };
    }

    /** Columns before now on an empty chart. */
    private function emptyPadding(GanttScale $scale): int
    {
        return $scale === GanttScale::Hours ? 2 : $this->padding($scale);
    }

    /** The start of the column holding `$at`. */
    private function align(GanttScale $scale, WeekStart $weekStart, DateTimeImmutable $at): DateTimeImmutable
    {
        $day = $at->setTime(0, 0);

        return match ($scale) {
            GanttScale::Hours  => $at->setTime((int) $at->format('G'), 0),
            GanttScale::Days   => $day,
            GanttScale::Weeks  => $day->modify('-' . (((int) $day->format('N') - $weekStart->isoDay() + 7) % 7) . ' days'),
            GanttScale::Months => $day->modify('first day of this month'),
        };
    }

    /** `$at` moved by `$slots` columns (negative: back). */
    private function step(GanttScale $scale, DateTimeImmutable $at, int $slots): DateTimeImmutable
    {
        $sign = $slots < 0 ? '-' : '+';
        $abs  = abs($slots);

        return match ($scale) {
            GanttScale::Hours  => $at->modify($sign . $abs . ' hours'),
            GanttScale::Days   => $at->modify($sign . $abs . ' days'),
            GanttScale::Weeks  => $at->modify($sign . ($abs * 7) . ' days'),
            GanttScale::Months => $at->modify($sign . $abs . ' months'),
        };
    }

    /** Columns from the one starting at `$from` to the one starting at `$to` (negative when `$to` is earlier). */
    private function slotsBetween(GanttScale $scale, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $days = (int) $from->diff($to)->format('%r%a');

        return match ($scale) {
            GanttScale::Hours  => intdiv($to->getTimestamp() - $from->getTimestamp(), 3600),
            GanttScale::Days   => $days,
            GanttScale::Weeks  => intdiv($days, 7),
            GanttScale::Months => ((int) $to->format('Y') * 12 + (int) $to->format('n')) - ((int) $from->format('Y') * 12 + (int) $from->format('n')),
        };
    }

    /**
     * @param list<Task> $tasks
     *
     * @return list<TaskGanttLink>
     */
    private function collectLinks(array $tasks): array
    {
        $links = [];

        foreach ($tasks as $task) {
            foreach ($task->getOutgoingDependencies() as $dependency) {
                $type = $dependency->getDependencyType();

                if ($type === TaskDependencyType::Blocks) {
                    $links[] = new TaskGanttLink(
                        fromId: $dependency->getSourceTask()->getId(),
                        toId: $dependency->getTargetTask()->getId(),
                        type: $type->value,
                    );

                    continue;
                }

                if ($type === TaskDependencyType::BlockedBy) {
                    $links[] = new TaskGanttLink(
                        fromId: $dependency->getTargetTask()->getId(),
                        toId: $dependency->getSourceTask()->getId(),
                        type: TaskDependencyType::Blocks->value,
                    );
                }
            }
        }

        return $links;
    }
}
