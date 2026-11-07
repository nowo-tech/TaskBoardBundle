<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\CalendarMode;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Service\TaskCalendarBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Spec 214, FR-019: the days of each mode.
 */
final class TaskCalendarBuilderTest extends TestCase
{
    private TaskCalendarBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TaskCalendarBuilder(new MockClock('2026-11-07 10:00:00'));
    }

    public function testAMonthCoversWholeWeeksFromTheWeekStart(): void
    {
        $day = new DateTimeImmutable('2026-02-15');

        [$from, $to] = $this->builder->range(CalendarMode::Month, $day, WeekStart::Monday);
        self::assertSame('2026-01-26', $from->format('Y-m-d'));
        self::assertSame('2026-03-02', $to->format('Y-m-d'));

        [$from, $to] = $this->builder->range(CalendarMode::Month, $day, WeekStart::Sunday);
        self::assertSame('2026-02-01', $from->format('Y-m-d'));
        self::assertSame('2026-03-01', $to->format('Y-m-d'));
    }

    public function testAWeekIsSevenDaysAndAListIsTheMonth(): void
    {
        $day = new DateTimeImmutable('2026-11-08');

        self::assertSame('2026-11-02', $this->builder->range(CalendarMode::Week, $day, WeekStart::Monday)[0]->format('Y-m-d'));
        self::assertSame('2026-11-08', $this->builder->range(CalendarMode::Week, $day, WeekStart::Sunday)[0]->format('Y-m-d'));
        self::assertSame('2026-11-01', $this->builder->range(CalendarMode::List, $day, WeekStart::Monday)[0]->format('Y-m-d'));
        self::assertSame('2026-12-01', $this->builder->range(CalendarMode::List, $day, WeekStart::Monday)[1]->format('Y-m-d'));
    }

    public function testPreviousAndNextStepByThePeriodFromTheEndOfAMonth(): void
    {
        $view = $this->builder->build(CalendarMode::Month, new DateTimeImmutable('2026-01-31'), WeekStart::Monday, []);
        self::assertSame('2025-12-01', $view->previous);
        self::assertSame('2026-02-01', $view->next);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $view->weekdays);

        $view = $this->builder->build(CalendarMode::Week, new DateTimeImmutable('2026-01-31'), WeekStart::Sunday, []);
        self::assertSame('2026-01-24', $view->previous);
        self::assertSame('2026-02-07', $view->next);
        self::assertSame([7, 1, 2, 3, 4, 5, 6], $view->weekdays);
        self::assertSame('2026-11-07', $view->today);
    }

    public function testADateIsARealDayInRangeOrToday(): void
    {
        self::assertSame('2026-02-28', $this->builder->day('2026-02-28')->format('Y-m-d'));
        foreach (['2026-02-31', 'nonsense', '0001-01-01', '9999-01-01', '', null, ['x']] as $value) {
            self::assertSame('2026-11-07', $this->builder->day($value)->format('Y-m-d'));
        }
    }

    public function testAnUndatedTaskIsNotPlacedAndBarsShareLanesOnlyWhenTheyDoNotOverlap(): void
    {
        $board = new TaskBoard('B', 'b', null);
        $task  = static function (string $title, ?string $start, ?string $due) use ($board): Task {
            $task = new Task(board: $board, title: $title, creator: null, dueAt: $due !== null ? new DateTimeImmutable($due) : null);
            $task->setStartAt($start !== null ? new DateTimeImmutable($start) : null);

            return $task;
        };
        $long     = $task('long', '2026-11-09', '2026-11-11');
        $inverted = $task('inverted', '2026-11-13', '2026-11-12');
        $view     = $this->builder->build(CalendarMode::Week, new DateTimeImmutable('2026-11-10'), WeekStart::Monday, [
            $task('none', null, null),
            $long,
            $task('overlap', null, '2026-11-10'),
            $task('after', null, '2026-11-12'),
            $inverted,
        ], [$long->getId() => true]);

        self::assertSame(4, $view->placed);
        self::assertTrue($view->anyEditable);
        $lanes = [];
        foreach ($view->weeks[0] as $day) {
            foreach ($day->chips as $chip) {
                $lanes[$chip->entry->task->getTitle()] = [$day->date, $chip->span, $chip->lane];
            }
        }
        self::assertSame(['2026-11-09', 3, 1], $lanes['long']);
        self::assertSame(['2026-11-10', 1, 2], $lanes['overlap']);
        self::assertSame('2026-11-12', $lanes['after'][0]);
        // Older data with the due date before the start draws as one day.
        self::assertSame(['2026-11-13', 1, 1], $lanes['inverted']);
    }

    public function testModesComeFromTheQuery(): void
    {
        self::assertSame(CalendarMode::Week, CalendarMode::fromQuery('week'));
        self::assertSame(CalendarMode::Month, CalendarMode::fromQuery('x'));
        self::assertSame(CalendarMode::Month, CalendarMode::fromQuery(['week']));
    }
}
