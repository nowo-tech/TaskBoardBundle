<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
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
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskApiTimeStoreInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
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
