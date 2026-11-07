<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.2.0 (spec 213): the time store. There is no delete: `DELETE .../time_entries/{id}` always answers 403 and tracked
 * time is never removed through the API (owner rule). Default: {@see DenyingTaskApiTimeEntries}, 403 `TIME_001`.
 */
interface TaskApiTimeEntriesInterface
{
    /**
     * @throws TaskApiRefusal
     */
    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage;

    /**
     * @throws TaskApiRefusal
     */
    public function log(TaskApiPrincipal $principal, ?Task $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry;
}
