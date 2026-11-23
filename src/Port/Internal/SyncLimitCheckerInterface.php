<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;

/**
 * Since 1.23: the host's quotas. Called before each page is written (`$newTasks` = creates + restores of the page, and
 * since 1.29.0 tasks the provider reopens; a restored task that stays completed does not count) and before each file is
 * downloaded (`$newBytes`). Since 1.29.0 also when a task is restored by hand, when a move reopens a completed task
 * (`$newTasks` = 1) and when deleting a column would reopen its completed tasks (`$newTasks` = how many). File imports and
 * a provider's checklist item can reopen a task with no call. null = allowed.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see SyncQuotaPolicyInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface SyncLimitCheckerInterface
{
    public function check(TaskBoard $board, int $newTasks, int $newBytes): ?LimitRefusal;
}
