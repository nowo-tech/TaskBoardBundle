<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.14.0 (Beacon spec 204, `calendar.day_annotation_provider`): working days, holidays and leave on a board's calendar.
 * Host port with bundle-owned value objects only (REQ-ARCH-001 rule 3). The bundle only draws what it gets: day shading and
 * label, and a warning on a task whose assignee is off on one of its days. The host keeps the data and decides what each
 * viewer may see: return only labels this viewer may read, and never a medical or other special category reason (use
 * "Absent"; GDPR art. 9).
 *
 * Called once per calendar page for the viewer and once for every assignee of the page together (no call per task or per
 * day). Resolve per call: no viewer in a property (FrankenPHP worker mode, REQ-RUNTIME-002).
 */
interface CalendarDayAnnotationProviderInterface
{
    /** The viewer's own days: the shading of the calendar (holidays, weekends of their schedule). */
    public function viewerDays(CalendarViewer $viewer, CalendarDateRange $range): CalendarDayAnnotations;

    /**
     * The days of the assignees of the tasks shown, in one call: user id => their days (an id left out has none).
     *
     * @param list<string> $assigneeIds user ids (`user_class::getId()` as strings)
     *
     * @return array<string, CalendarDayAnnotations>
     */
    public function assigneeDays(CalendarViewer $viewer, array $assigneeIds, CalendarDateRange $range): array;
}
