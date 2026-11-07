<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskCalendarFilter;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.1.0 (spec 214, FR-018, FR-021, FR-022): the calendar's queries, bounded by the visible range. Implemented by the bundle's
 * Doctrine task repository; a host repository aliased to {@see TaskRepositoryInterface} needs it for `calendar.enabled`.
 */
interface TaskCalendarRepositoryInterface
{
    /**
     * At most `$limit` active dated tasks overlapping the days from `$from` up to, not including, `$to`, earliest first, each with
     * its column. Their members are not loaded: the calendar does not show them.
     *
     * @return list<Task>
     */
    public function findForCalendar(TaskBoard $board, DateTimeImmutable $from, DateTimeImmutable $to, TaskCalendarFilter $filter, int $limit): array;

    /** How many tasks {@see self::findForCalendar()} would find with no limit. */
    public function countForCalendar(TaskBoard $board, DateTimeImmutable $from, DateTimeImmutable $to, TaskCalendarFilter $filter): int;

    /** How many active tasks have no start and no due date. */
    public function countUndated(TaskBoard $board, TaskCalendarFilter $filter): int;

    /**
     * The people assigned to a task of the board, for the filter: user id => the user.
     *
     * @return array<string, object>
     */
    public function findCalendarAssignees(TaskBoard $board): array;
}
