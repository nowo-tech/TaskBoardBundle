<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Recurrence;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrencePlanner;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Spec 215, FR-002 and SC-003: the planner's table — month ends, leap years, weekday sets, end conditions, skipped dates.
 */
final class TaskRecurrencePlannerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: TaskRecurrenceRule, 1: string|null, 2: int, 3: list<string>, 4: int, 5: list<string>}>
     */
    public static function rules(): iterable
    {
        $fixed = static fn (string $unit, int $interval, string $anchor, array $weekdays = [], ?int $monthDay = null, ?string $endDate = null, ?int $endCount = null): TaskRecurrenceRule => new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, $unit, $interval, new DateTimeImmutable($anchor), $weekdays, $monthDay, $endDate !== null ? new DateTimeImmutable($endDate) : null, $endCount);

        yield 'every 3 days' => [$fixed('day', 3, '2026-11-01'), '2026-11-01', 3, [], 1, ['2026-11-04', '2026-11-07', '2026-11-10']];
        yield 'weekdays Mon and Fri, every 2 weeks' => [$fixed('week', 2, '2026-11-02', [5, 1]), '2026-11-02', 4, [], 1, ['2026-11-06', '2026-11-16', '2026-11-20', '2026-11-30']];
        yield 'weekly on the anchor day' => [$fixed('week', 1, '2026-11-04'), '2026-11-04', 2, [], 1, ['2026-11-11', '2026-11-18']];
        yield 'week dates before the anchor never come' => [$fixed('week', 1, '2026-11-04', [1, 3]), null, 2, [], 1, ['2026-11-04', '2026-11-09']];
        yield 'day 31 clamps to the month' => [$fixed('month', 1, '2026-01-31', [], 31), '2026-01-31', 3, [], 1, ['2026-02-28', '2026-03-31', '2026-04-30']];
        yield 'last day of the month' => [$fixed('month', 1, '2028-01-10', [], TaskRecurrenceRule::LAST_DAY), null, 3, [], 1, ['2028-01-31', '2028-02-29', '2028-03-31']];
        yield 'a month day before the anchor starts next month' => [$fixed('month', 1, '2026-11-15', [], 1), null, 2, [], 1, ['2026-12-01', '2027-01-01']];
        yield 'leap day yearly' => [$fixed('year', 1, '2024-02-29'), '2024-02-29', 4, [], 1, ['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29']];
        yield 'end date inclusive' => [$fixed('day', 1, '2026-11-01', endDate: '2026-11-03'), '2026-11-01', 5, [], 1, ['2026-11-02', '2026-11-03']];
        yield 'end after 3 occurrences, the first counted' => [$fixed('day', 1, '2026-11-01', endCount: 3), '2026-11-01', 5, [], 1, ['2026-11-02', '2026-11-03']];
        yield 'end count already reached' => [$fixed('day', 1, '2026-11-01', endCount: 3), '2026-11-02', 5, [], 3, []];
        yield 'skipped dates do not use up the count' => [$fixed('day', 1, '2026-11-01', endCount: 3), '2026-11-01', 5, ['2026-11-02'], 1, ['2026-11-03', '2026-11-04']];
        yield 'none asked' => [$fixed('day', 1, '2026-11-01'), null, 0, [], 1, []];
        yield 'after completion has no dates' => [new TaskRecurrenceRule(TaskRecurrenceRule::MODE_AFTER_COMPLETION, 'day', 3, new DateTimeImmutable('2026-11-01')), null, 3, [], 1, []];
    }

    /**
     * @param list<string> $skipped
     * @param list<string> $expected
     */
    #[DataProvider('rules')]
    public function testNextDates(TaskRecurrenceRule $rule, ?string $after, int $count, array $skipped, int $created, array $expected): void
    {
        $dates = (new TaskRecurrencePlanner())->nextDates($rule, $after !== null ? new DateTimeImmutable($after) : null, $count, $skipped, $created);

        self::assertSame($expected, array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates));
    }

    public function testAfterCompletionCountsFromTheCompletionDay(): void
    {
        $planner = new TaskRecurrencePlanner();
        $rule    = new TaskRecurrenceRule(TaskRecurrenceRule::MODE_AFTER_COMPLETION, 'day', 3, new DateTimeImmutable('2026-11-01'), endDate: new DateTimeImmutable('2026-11-20'));

        self::assertSame('2026-11-13', $planner->afterCompletion($rule, new DateTimeImmutable('2026-11-10 18:30'))?->format('Y-m-d'));
        self::assertSame('2026-11-16', $planner->afterCompletion($rule, new DateTimeImmutable('2026-11-10'), 2)?->format('Y-m-d'), 'the next one skipped');
        self::assertNull($planner->afterCompletion($rule, new DateTimeImmutable('2026-11-19')), 'past the end date');
        $monthly = new TaskRecurrenceRule(TaskRecurrenceRule::MODE_AFTER_COMPLETION, 'month', 1, new DateTimeImmutable('2026-01-01'));
        self::assertSame('2026-02-28', $planner->afterCompletion($monthly, new DateTimeImmutable('2026-01-31'))?->format('Y-m-d'));
    }

    public function testARuleThatNeverMatchesStops(): void
    {
        $rule = new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'day', 1, new DateTimeImmutable('2026-01-01'));

        self::assertCount(5000, (new TaskRecurrencePlanner())->nextDates($rule, null, 9000));
    }
}
