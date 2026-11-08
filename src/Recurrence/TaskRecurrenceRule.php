<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence;

use DateTimeImmutable;

/**
 * Since 2.3.0 (spec 215): how a series repeats. Dates are days at midnight, in the application's timezone.
 *
 * `fixed`: on calendar dates from `anchor` (every `interval` days, weeks on `weekdays` (ISO 1-7), months on `monthDay`
 * (1-31, clamped to the month, or -1 for its last day) or years on the anchor's day). `after_completion`: `interval` units
 * after the previous occurrence is completed. Ends never, after `endDate` (inclusive) or after `endCount` occurrences.
 */
final readonly class TaskRecurrenceRule
{
    public const MODE_FIXED = 'fixed';

    public const MODE_AFTER_COMPLETION = 'after_completion';

    public const UNITS = ['day', 'week', 'month', 'year'];

    public const LAST_DAY = -1;

    /**
     * @param list<int> $weekdays ISO days (1 Monday … 7 Sunday); empty: the anchor's
     */
    public function __construct(
        public string $mode,
        public string $unit,
        public int $interval,
        public DateTimeImmutable $anchor,
        public array $weekdays = [],
        public ?int $monthDay = null,
        public ?DateTimeImmutable $endDate = null,
        public ?int $endCount = null,
    ) {
    }
}
