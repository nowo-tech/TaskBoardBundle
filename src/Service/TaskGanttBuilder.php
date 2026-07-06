<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskGanttItem;
use Nowo\TaskBoardBundle\Dto\TaskGanttLink;
use Nowo\TaskBoardBundle\Dto\TaskGanttTimeline;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;

/**
 * Builds Gantt timeline data from board tasks.
 */
final readonly class TaskGanttBuilder
{
    private const DEFAULT_BAR_DAYS   = 1;
    private const MINUTES_PER_DAY    = 480;
    private const RANGE_PADDING_DAYS = 2;
    private const EMPTY_RANGE_DAYS   = 14;

    /**
     * @param list<Task> $tasks
     */
    public function build(array $tasks): TaskGanttTimeline
    {
        if ($tasks === []) {
            return $this->emptyTimeline();
        }

        /** @var array<string, list<Task>> $childrenByParent */
        $childrenByParent = [];
        /** @var list<Task> $roots */
        $roots = [];

        foreach ($tasks as $task) {
            $parent = $task->getParent();
            if ($parent === null) {
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

        $today  = new DateTimeImmutable('today');
        $items  = [];
        $minDay = null;
        $maxDay = null;

        foreach ($flat as $entry) {
            [$startDay, $endDay] = $this->resolveDateRange($entry['task'], $today);
            $minDay              = $minDay === null ? $startDay : min($minDay, $startDay);
            $maxDay              = $maxDay === null ? $endDay : max($maxDay, $endDay);

            $items[] = [
                'entry'    => $entry,
                'startDay' => $startDay,
                'endDay'   => $endDay,
            ];
        }

        $rangeStart = $minDay->modify('-' . self::RANGE_PADDING_DAYS . ' days');
        $rangeEnd   = $maxDay->modify('+' . self::RANGE_PADDING_DAYS . ' days');
        $dayKeys    = $this->dayKeysBetween($rangeStart, $rangeEnd);
        $indexByDay = array_flip($dayKeys);

        $ganttItems = [];
        foreach ($items as $item) {
            $task     = $item['entry']['task'];
            $startKey = $item['startDay']->format('Y-m-d');
            $endKey   = $item['endDay']->format('Y-m-d');
            $startIdx = $indexByDay[$startKey] ?? 0;
            $endIdx   = $indexByDay[$endKey] ?? $startIdx;

            $ganttItems[] = new TaskGanttItem(
                id: $task->getId(),
                title: $task->getTitle(),
                startDate: $startKey,
                endDate: $endKey,
                startIndex: $startIdx,
                endIndex: $endIdx,
                progress: $task->isCompleted() ? 100 : 0,
                priority: $task->getPriority()->value,
                columnName: $task->getColumn()?->getName(),
                depth: $item['entry']['depth'],
                completed: $task->isCompleted(),
            );
        }

        return new TaskGanttTimeline(
            items: $ganttItems,
            links: $this->collectLinks($tasks),
            rangeStart: $rangeStart->format('Y-m-d'),
            rangeEnd: $rangeEnd->format('Y-m-d'),
            dayKeys: $dayKeys,
            today: $today->format('Y-m-d'),
        );
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
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function resolveDateRange(Task $task, DateTimeImmutable $today): array
    {
        $start = $task->getCreatedAt()->setTime(0, 0);
        $end   = $task->getDueAt()?->setTime(0, 0);

        if ($end === null && $task->getEstimatedMinutes() !== null && $task->getEstimatedMinutes() > 0) {
            $durationDays = max(self::DEFAULT_BAR_DAYS, (int) ceil($task->getEstimatedMinutes() / self::MINUTES_PER_DAY));
            $end          = $start->modify('+' . ($durationDays - 1) . ' days');
        }

        if ($end === null) {
            $end = $start->modify('+' . (self::DEFAULT_BAR_DAYS - 1) . ' days');
        }

        if ($task->isCompleted() && $task->getCompletedAt() instanceof DateTimeImmutable) {
            $completedDay = $task->getCompletedAt()->setTime(0, 0);
            if ($completedDay < $end) {
                $end = $completedDay;
            }
        }

        if ($end < $start) {
            $end = $start;
        }

        if ($start < $today && !$task->isCompleted() && $end < $today) {
            $end = $today;
        }

        return [$start, $end];
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

    /** @return list<string> */
    private function dayKeysBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $keys    = [];
        $current = $start;
        while ($current <= $end) {
            $keys[]  = $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }

        return $keys;
    }

    private function emptyTimeline(): TaskGanttTimeline
    {
        $today      = new DateTimeImmutable('today');
        $rangeStart = $today->modify('-' . self::RANGE_PADDING_DAYS . ' days');
        $rangeEnd   = $today->modify('+' . (self::EMPTY_RANGE_DAYS - self::RANGE_PADDING_DAYS) . ' days');

        return new TaskGanttTimeline(
            items: [],
            links: [],
            rangeStart: $rangeStart->format('Y-m-d'),
            rangeEnd: $rangeEnd->format('Y-m-d'),
            dayKeys: $this->dayKeysBetween($rangeStart, $rangeEnd),
            today: $today->format('Y-m-d'),
        );
    }
}
