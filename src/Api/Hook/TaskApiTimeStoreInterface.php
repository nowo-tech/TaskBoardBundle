<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see TaskApiTimeEntriesInterface}; `log()` gets the task
 * as a {@see TaskSnapshot}. There is still no delete: tracked time is never removed through the API (owner rule).
 * Implement {@see TaskApiTimeEntryBillableInterface} too for `PUT .../time_entries/{timer_id}`.
 *
 * Set `api.hooks.time_entries` to a service implementing it. A service that also implements the deprecated interface is
 * asked through that one until 3.0.
 */
interface TaskApiTimeStoreInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage;

    /**
     * @throws TaskApiRefusal
     */
    public function log(TaskApiPrincipal $principal, ?TaskSnapshot $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry;
}
