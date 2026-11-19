<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Calendar\CalendarEvent;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEventSourceInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;

/**
 * Since 2.24.0: a second calendar event source of the functional tests, tagged by hand and read-only (no reschedule): its
 * events never move, even when they say they are not read-only.
 */
final class ReadOnlyCalendarEvents implements CalendarEventSourceInterface
{
    public string $key = 'bookings';

    /** @var array<string, list<CalendarEvent>> viewer user id => events */
    public array $events = [];

    public function key(): string
    {
        return $this->key;
    }

    public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable
    {
        return $this->events[$viewer->userId] ?? [];
    }
}
