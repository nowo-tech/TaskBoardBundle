<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Gantt;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskGanttSlot;
use Nowo\TaskBoardBundle\Dto\TaskGanttTimeline;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Gantt\ConfiguredWeekStartResolver;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * Since 1.15: the Gantt scales (hours, days, weeks, months), their bounded ranges and the week start.
 */
final class GanttScalesTest extends TestCase
{
    private const NOW = '2026-10-28 10:30:00'; // a Wednesday

    private TaskBoard $board;

    protected function setUp(): void
    {
        $this->board = new TaskBoard('Board', 'board', new stdClass());
    }

    public function testDaysScaleMarksWeekendsTodayAndWeekStarts(): void
    {
        $task = $this->task('Plan', '2026-10-27 09:00', '2026-10-30');

        $monday = $this->build([$task], GanttScale::Days, WeekStart::Monday);
        self::assertSame(GanttScale::Days, $monday->scale);
        self::assertSame(WeekStart::Monday, $monday->weekStart);
        self::assertFalse($monday->clamped);
        self::assertCount(TaskGanttBuilder::MIN_RANGE_DAYS, $monday->slots);
        self::assertSame('2026-10-25', $monday->rangeStart);
        self::assertSame(array_map(static fn (TaskGanttSlot $slot): string => $slot->date, $monday->slots), $monday->dayKeys);
        self::assertSame(3, $monday->todayIndex);
        self::assertTrue($monday->slots[3]->today);
        self::assertSame([2, 5], [$monday->items[0]->startIndex, $monday->items[0]->endIndex]);
        self::assertTrue($monday->slots[0]->weekend, 'the 25th is a Sunday');
        self::assertTrue($monday->slots[1]->periodStart, 'the week starts on Monday the 26th');
        self::assertFalse($monday->slots[0]->periodStart);
        self::assertTrue($monday->slots[0]->groupStart);
        self::assertTrue($monday->slots[7]->groupStart, 'November starts a new month');
        self::assertNull($monday->slots[0]->hour);

        $sunday = $this->build([$task], GanttScale::Days, WeekStart::Sunday);
        self::assertTrue($sunday->slots[0]->periodStart);
        self::assertFalse($sunday->slots[1]->periodStart);
    }

    public function testHoursScaleDrawsDateOnlyTasksOverWholeDaysAndTimedTasksByTheHour(): void
    {
        $dateOnly = $this->task('Whole days', '2026-10-27 09:20', '2026-10-28');
        $timed    = $this->task('Timed', '2026-10-27 09:20', '2026-10-28 15:00');

        $timeline = $this->build([$dateOnly, $timed], GanttScale::Hours);

        self::assertCount(48, $timeline->slots);
        self::assertSame('2026-10-27T00', $timeline->slots[0]->key);
        self::assertSame(0, $timeline->slots[0]->hour);
        self::assertTrue($timeline->slots[0]->periodStart);
        self::assertTrue($timeline->slots[24]->groupStart);
        self::assertFalse($timeline->slots[1]->groupStart);
        self::assertSame(34, $timeline->todayIndex);
        self::assertSame([0, 47], [$timeline->items[0]->startIndex, $timeline->items[0]->endIndex]);
        self::assertNull($timeline->items[0]->startTime);
        self::assertSame([9, 38], [$timeline->items[1]->startIndex, $timeline->items[1]->endIndex]);
        self::assertSame(['09:00', '15:00'], [$timeline->items[1]->startTime, $timeline->items[1]->endTime]);
    }

    public function testHoursOfOverdueCompletedAndBackdatedTimedTasks(): void
    {
        $overdue = $this->task('Overdue', '2026-10-28 06:10', '2026-10-28 08:00');
        $done    = $this->task('Done early', '2026-10-28 06:10', '2026-10-28 20:00');
        $done->markCompleted();
        (new ReflectionProperty(Task::class, 'completedAt'))->setValue($done, new DateTimeImmutable('2026-10-28 09:00'));
        $late    = $this->task('Created after its due time', '2026-10-28 16:40', '2026-10-28 15:00');
        $weekend = $this->task('Saturday', '2026-10-31 08:00', '2026-10-31');

        $timeline = $this->build([$overdue, $done, $late, $weekend], GanttScale::Hours);
        $byTitle  = [];
        foreach ($timeline->items as $item) {
            $byTitle[$item->title] = $item;
        }

        self::assertSame(['06:00', '10:30'], [$byTitle['Overdue']->startTime, $byTitle['Overdue']->endTime]);
        self::assertSame('09:00', $byTitle['Done early']->endTime);
        self::assertSame(['14:00', '15:00'], [$byTitle['Created after its due time']->startTime, $byTitle['Created after its due time']->endTime]);
        $saturday = array_values(array_filter($timeline->slots, static fn (TaskGanttSlot $slot): bool => $slot->date === '2026-10-31'));
        self::assertTrue($saturday[0]->weekend);
    }

    public function testWeeksFollowTheWeekStart(): void
    {
        $task = $this->task('Plan', '2026-10-20 09:00', '2026-11-12');

        $monday = $this->build([$task], GanttScale::Weeks, WeekStart::Monday);
        self::assertCount(GanttScale::Weeks->minSlots(), $monday->slots);
        self::assertSame('2026-10-12', $monday->slots[0]->date);
        self::assertSame('2026-10-18', $monday->slots[0]->lastDate);
        self::assertSame(1, $monday->slots[0]->weekday);
        self::assertFalse($monday->slots[0]->weekend);
        self::assertFalse($monday->slots[0]->periodStart);
        self::assertSame(2, $monday->todayIndex);
        self::assertSame([1, 4], [$monday->items[0]->startIndex, $monday->items[0]->endIndex]);
        self::assertFalse($monday->slots[2]->groupStart);
        self::assertTrue($monday->slots[3]->groupStart, 'November starts on the week of the 2nd');

        $sunday = $this->build([$task], GanttScale::Weeks, WeekStart::Sunday);
        self::assertSame('2026-10-11', $sunday->slots[0]->date);
        self::assertSame(7, $sunday->slots[0]->weekday);
    }

    public function testMonthsGroupByYear(): void
    {
        $task = $this->task('Plan', '2026-09-15 09:00', '2027-02-01');

        $timeline = $this->build([$task], GanttScale::Months);

        self::assertCount(12, $timeline->slots);
        self::assertSame('2026-08', $timeline->slots[0]->key);
        self::assertSame('2026-08-31', $timeline->slots[0]->lastDate);
        self::assertTrue($timeline->slots[5]->groupStart, 'January 2027');
        self::assertSame(2027, $timeline->slots[5]->year);
        self::assertFalse($timeline->slots[4]->groupStart);
        self::assertSame(2, $timeline->todayIndex);
        self::assertSame([1, 6], [$timeline->items[0]->startIndex, $timeline->items[0]->endIndex]);
    }

    public function testALongPlanIsClampedAroundNow(): void
    {
        $long = $this->task('Three years', '2025-01-01 09:00', '2028-06-30');
        $past = $this->task('Long ago', '2025-01-01 09:00', '2025-01-03');
        $past->markCompleted();
        (new ReflectionProperty(Task::class, 'completedAt'))->setValue($past, new DateTimeImmutable('2025-01-03'));

        $timeline = $this->build([$long, $past], GanttScale::Days);

        self::assertTrue($timeline->clamped);
        self::assertCount(GanttScale::Days->maxSlots(), $timeline->slots);
        self::assertSame(91, $timeline->todayIndex);
        self::assertTrue($timeline->items[0]->clippedStart);
        self::assertTrue($timeline->items[0]->clippedEnd);
        self::assertSame([0, 365], [$timeline->items[0]->startIndex, $timeline->items[0]->endIndex]);
        self::assertFalse($timeline->items[1]->visible);

        $hours = $this->build([$long], GanttScale::Hours);
        self::assertTrue($hours->clamped);
        self::assertCount(168, $hours->slots);
        self::assertSame(42, $hours->todayIndex);
    }

    public function testTheWindowStaysInsideThePlanNearItsEndAndBeforeItsStart(): void
    {
        $endingSoon = $this->build([$this->task('Ends soon', '2024-06-01 09:00', '2026-11-05')], GanttScale::Days);
        self::assertTrue($endingSoon->clamped);
        self::assertSame('2026-11-07', $endingSoon->rangeEnd);

        $future = $this->build([$this->task('Later', '2027-01-01 09:00', '2029-01-01')], GanttScale::Days);
        self::assertTrue($future->clamped);
        self::assertSame('2026-12-30', $future->rangeStart);
        self::assertNull($future->todayIndex);
    }

    public function testEmptyTimelinesHaveTheMinimumColumnsAroundNow(): void
    {
        foreach (GanttScale::cases() as $scale) {
            $timeline = $this->build([], $scale);
            self::assertSame([], $timeline->items);
            self::assertCount($scale->minSlots(), $timeline->slots, $scale->value);
            self::assertNotNull($timeline->todayIndex);
        }
    }

    public function testTheBarCarriesTheTimeSpentAgainstTheEstimate(): void
    {
        $user = new stdClass();
        $task = new Task($this->board, 'Estimated', $user, estimatedMinutes: 60);
        $task->addTimeEntry(new TaskTimeEntry($task, $user, 90, new DateTimeImmutable()));

        $item = (new TaskGanttBuilder())->build([$task])->items[0];

        self::assertSame([60, 90, 150, 'over', true], [$item->estimatedMinutes, $item->loggedMinutes, $item->spentPercent, $item->deviationStatus, $item->overrun]);
    }

    public function testTheStartDateStartsTheBarInEveryScale(): void
    {
        $task = $this->task('Planned', '2026-09-01 09:00', '2026-11-04');
        $task->setStartAt(new DateTimeImmutable('2026-10-29'));
        self::assertSame('2026-10-29', $task->getPlannedStart()->format('Y-m-d'));

        $days = $this->build([$task], GanttScale::Days);
        self::assertSame('2026-10-29', $days->items[0]->startDate);
        self::assertSame('2026-10-27', $days->rangeStart);
        self::assertSame([2, 8], [$days->items[0]->startIndex, $days->items[0]->endIndex]);

        $timed = $this->task('Timed', '2026-09-01 09:00', '2026-10-29 15:00');
        $timed->setStartAt(new DateTimeImmutable('2026-10-29'));
        $hours = $this->build([$timed], GanttScale::Hours);
        self::assertSame(['00:00', '15:00'], [$hours->items[0]->startTime, $hours->items[0]->endTime]);

        $task->setStartAt(null);
        self::assertSame('2026-09-01', $task->getPlannedStart()->format('Y-m-d'));
    }

    public function testScaleAndWeekStartValues(): void
    {
        self::assertSame(GanttScale::Weeks, GanttScale::fromQuery('weeks'));
        self::assertSame(GanttScale::Days, GanttScale::fromQuery('decades'));
        self::assertSame(GanttScale::Days, GanttScale::fromQuery(null));
        self::assertSame([48, 21, 12, 12], array_map(static fn (GanttScale $scale): int => $scale->minSlots(), GanttScale::cases()));
        self::assertSame([168, 366, 104, 60], array_map(static fn (GanttScale $scale): int => $scale->maxSlots(), GanttScale::cases()));
        self::assertSame([1, 7], [WeekStart::Monday->isoDay(), WeekStart::Sunday->isoDay()]);
        self::assertSame(WeekStart::Monday, (new ConfiguredWeekStartResolver())->resolve());
        self::assertSame(WeekStart::Sunday, (new ConfiguredWeekStartResolver('sunday'))->resolve());
        self::assertSame(WeekStart::Monday, (new ConfiguredWeekStartResolver('friday'))->resolve());
    }

    /**
     * @param list<Task> $tasks
     */
    private function build(array $tasks, GanttScale $scale, WeekStart $weekStart = WeekStart::Monday): TaskGanttTimeline
    {
        return (new TaskGanttBuilder())->build($tasks, $scale, $weekStart, new DateTimeImmutable(self::NOW));
    }

    private function task(string $title, string $createdAt, ?string $dueAt = null): Task
    {
        $task = new Task($this->board, $title, new stdClass(), dueAt: $dueAt !== null ? new DateTimeImmutable($dueAt) : null);
        (new ReflectionProperty(Task::class, 'createdAt'))->setValue($task, new DateTimeImmutable($createdAt));

        return $task;
    }
}
