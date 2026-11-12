<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotation;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotationProviderInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotations;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;

/**
 * Since 2.14.0: the host's calendar days of the functional tests: the annotations a test sets per user id, and every call
 * (to prove one call per page for the viewer and one for all assignees).
 */
final class ScriptedCalendarDays implements CalendarDayAnnotationProviderInterface
{
    /** @var array<string, list<CalendarDayAnnotation>> viewer user id => their days */
    public array $viewer = [];

    /** @var array<string, list<CalendarDayAnnotation>> assignee user id => their days */
    public array $people = [];

    /** @var list<array{string, CalendarViewer, CalendarDateRange, list<string>}> */
    public array $calls = [];

    public function viewerDays(CalendarViewer $viewer, CalendarDateRange $range): CalendarDayAnnotations
    {
        $this->calls[] = ['viewer', $viewer, $range, []];

        return new CalendarDayAnnotations(array_filter($this->viewer[$viewer->userId] ?? [], static fn (CalendarDayAnnotation $day): bool => $range->contains($day->date)));
    }

    public function assigneeDays(CalendarViewer $viewer, array $assigneeIds, CalendarDateRange $range): array
    {
        $this->calls[] = ['assignees', $viewer, $range, $assigneeIds];
        $days          = [];
        foreach ($assigneeIds as $id) {
            if (isset($this->people[$id])) {
                $days[$id] = new CalendarDayAnnotations($this->people[$id]);
            }
        }

        return $days;
    }
}
