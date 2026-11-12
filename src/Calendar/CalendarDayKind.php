<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.14.0 (Beacon spec 204): what a calendar day annotation stands for. The bundle only styles by it; the text shown is
 * always the host's label.
 */
enum CalendarDayKind: string
{
    case Holiday = 'holiday';
    case Weekend = 'weekend';
    case Absent  = 'absent';
    case Other   = 'other';
}
