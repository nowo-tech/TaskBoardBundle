<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

use function is_string;

/**
 * Since 1.15: the time unit of one Gantt column (`?scale=` of the Gantt page).
 *
 * Every scale draws at least {@see self::minSlots()} and at most {@see self::maxSlots()} columns: a longer plan is
 * clamped to a window around today (the page says so), so an hourly grid never grows without bounds.
 */
enum GanttScale: string
{
    case Hours  = 'hours';
    case Days   = 'days';
    case Weeks  = 'weeks';
    case Months = 'months';

    /** The scale of a query value; unknown or empty values give the default (days). */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Days) : self::Days;
    }

    /** Fewest columns drawn (a short plan is padded on the right). */
    public function minSlots(): int
    {
        return match ($this) {
            self::Hours  => 48,
            self::Days   => 21,
            self::Weeks  => 12,
            self::Months => 12,
        };
    }

    /** Most columns drawn: 7 days of hours, a year of days, two years of weeks, five years of months. */
    public function maxSlots(): int
    {
        return match ($this) {
            self::Hours  => 168,
            self::Days   => 366,
            self::Weeks  => 104,
            self::Months => 60,
        };
    }
}
