<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

use function sprintf;

/**
 * Since 2.14.0: the days a calendar page shows, both included (`Y-m-d`).
 */
final readonly class CalendarDateRange
{
    public function __construct(
        public string $from,
        public string $to,
    ) {
        foreach ([$from, $to] as $date) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException(sprintf('A calendar range needs Y-m-d dates, "%s" given.', $date));
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException(sprintf('A calendar range starts on or before its end, %s > %s given.', $from, $to));
        }
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }
}
