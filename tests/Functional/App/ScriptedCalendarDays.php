<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotation;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotationProviderInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotations;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use RuntimeException;

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

    /** Since 2.14.1: thrown by the next viewerDays() call when set (a failing host provider). */
    public ?RuntimeException $failure = null;

    /**
     * Since 2.15.1 (REQ-SEC-006): asked id => the key assigneeDays() answers it under, so a test can answer with a
     * look-alike or a differently-typed key (a host that does not key its answer by the exact ids asked).
     *
     * @var array<string, array-key>
     */
    public array $answerKeys = [];

    public function viewerDays(CalendarViewer $viewer, CalendarDateRange $range): CalendarDayAnnotations
    {
        $this->calls[] = ['viewer', $viewer, $range, []];
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        return new CalendarDayAnnotations(array_filter($this->viewer[$viewer->userId] ?? [], static fn (CalendarDayAnnotation $day): bool => $range->contains($day->date)));
    }

    public function assigneeDays(CalendarViewer $viewer, array $assigneeIds, CalendarDateRange $range): array
    {
        $this->calls[] = ['assignees', $viewer, $range, $assigneeIds];
        $days          = [];
        foreach ($assigneeIds as $id) {
            if (isset($this->people[$id])) {
                $days[$this->answerKeys[$id] ?? $id] = new CalendarDayAnnotations($this->people[$id]);
            }
        }

        return $days;
    }
}
