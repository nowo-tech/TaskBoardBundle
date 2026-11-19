<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.24.0: the answer of a {@see CalendarEventReschedulerInterface}, and the HTTP answer the calendar gives for it.
 */
enum CalendarEventRescheduleStatus: string
{
    /** Moved: 200. */
    case Ok = 'ok';
    /** The event changed since the calendar showed it (start or version), nothing written: 409 with `X-Error-Code`. */
    case Conflict = 'conflict';
    /** A database lock conflict: 409 (or the shared policy's 503) with `X-Conflict-Code` (REQ-RUNTIME-003 rule 6). */
    case LockConflict = 'lock_conflict';
    /** The viewer may see the event but not move it: 403. */
    case Forbidden = 'forbidden';
    /** No such event for this viewer and tenant: 404. */
    case NotFound = 'not_found';
}
