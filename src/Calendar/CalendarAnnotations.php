<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.14.0: what a calendar page draws from the host's annotations: the viewer's days and, per task, the days its
 * assignees are off.
 */
final readonly class CalendarAnnotations
{
    /**
     * @param array<string, list<CalendarTaskWarning>> $warnings task id => warnings
     */
    public function __construct(
        public CalendarDayAnnotations $days,
        public array $warnings = [],
    ) {
    }

    /**
     * @return list<CalendarTaskWarning>
     */
    public function warningsFor(string $taskId): array
    {
        return $this->warnings[$taskId] ?? [];
    }
}
