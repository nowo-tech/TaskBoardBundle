<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.24.0: timed events of another part of the application (meetings, calls, bookings...) shown on a board's
 * calendar next to its tasks. A host or another bundle implements it; with `autoconfigure` the service is tagged
 * `nowo_task_board.calendar_event_source` (or tag it by hand). Several sources may exist; each has its own key.
 *
 * Bundle-owned value objects only (REQ-ARCH-001 rule 3). The source owns its data and decides who sees what: it gets the
 * viewer (user id, board id and the board's tenant key) and returns only the events this viewer may read in that tenant.
 * The bundle never adds an event the source did not return for this viewer, and drops an event whose tenant key is not the
 * viewer's, byte for byte.
 *
 * Called once per calendar page and source (never per day or per task). Resolve everything per call: no viewer, tenant or
 * result in a property (FrankenPHP worker mode with the kernel kept between requests, REQ-RUNTIME-002).
 */
interface CalendarEventSourceInterface
{
    /**
     * A stable key of this source, unique in the application: 1 to 64 characters of `a-z`, `0-9`, `_`, `.` and `-`, starting
     * with a letter or a digit. It is sent back with a reschedule and used in the markup (`data-event-source`).
     */
    public function key(): string;

    /**
     * The events of the viewer that overlap the range: a timed event with `start < range.to` and `end > range.from`, an all-day
     * event with a day between `range.days.from` and `range.days.to`. Anything else is dropped.
     *
     * @return iterable<CalendarEvent>
     */
    public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable;
}
