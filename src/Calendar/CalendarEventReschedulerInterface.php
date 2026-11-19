<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.24.0: a {@see CalendarEventSourceInterface} whose events can be moved to another day from the calendar (drag, or the
 * Move dialog). Optional: a source without it shows read-only events. An event is movable only when its source implements this
 * interface and the event is not `readOnly`.
 *
 * The bundle checks the CSRF token, the signed-in user and that the viewer may open the board. Then it reads the event again
 * through {@see self::events()} for this viewer on the day the page showed it (never trusting the browser, audit UW-TB-1):
 * it answers 404 when the event is not returned, 403 when it is `readOnly`, and 409 (`CALENDAR_EVENT_CHANGED`) when its
 * start, end, all-day flag or version differ from what the page showed, without calling this method. Otherwise it computes
 * the new start and end from the stored values (same time of day and duration, see {@see CalendarEventReschedule}) and calls
 * this method, with those stored values as `expectedStart`, `expectedEnd` and `expectedVersion`.
 *
 * The source still decides, in its own transaction, because the event may change between the read and the write: whether
 * the event exists for this viewer and tenant (else `notFound()`), whether the viewer may move it (else `forbidden()`: repeat
 * the permission check, do not rely on `readOnly` alone), and whether it still has `expectedStart`, `expectedEnd` and
 * `expectedVersion` (else `conflict()`, nothing written). A database lock conflict is `lockConflict()` (or thrown: the bundle
 * reads the exception chain). The bundle writes nothing in that request before calling the source, so only the source knows
 * whether part of its work was committed before a conflict: say so with `lockConflict(committed: true)` (or a write unit's
 * `ConcurrencyConflict` with `committed`), and the answer is `CONFLICT_002` instead of `CONFLICT_001` (REQ-RUNTIME-003
 * rule 6). Never trust the event id alone: look it up within the viewer's tenant.
 */
interface CalendarEventReschedulerInterface extends CalendarEventSourceInterface
{
    public function reschedule(CalendarViewer $viewer, CalendarEventReschedule $change): CalendarEventRescheduleResult;
}
