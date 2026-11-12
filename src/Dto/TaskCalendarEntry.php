<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Calendar\CalendarTaskWarning;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.1.0 (spec 214, FR-020): a task on the calendar: its stored days and the days it covers (Y-m-d).
 */
final readonly class TaskCalendarEntry
{
    public function __construct(
        public Task $task,
        public ?string $start,
        public ?string $due,
        public string $first,
        public string $last,
        public bool $overdue,
        public bool $editable,
        // Since 2.14.0 (calendar.day_annotation_provider): the days of the page its assignees are off.
        /** @var list<CalendarTaskWarning> */
        public array $warnings = [],
    ) {
    }

    /** Whether it has two dates, so it has two edges to drag. */
    public function hasRange(): bool
    {
        return $this->start !== null && $this->due !== null;
    }
}
