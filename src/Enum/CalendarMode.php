<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

use function is_string;

/**
 * Since 2.1.0 (spec 214, FR-019): what the calendar page shows (`?mode=`).
 */
enum CalendarMode: string
{
    case Month = 'month';
    case Week  = 'week';
    case List  = 'list';

    /** The mode of a query value; unknown or empty values give the default (month). */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Month) : self::Month;
    }
}
