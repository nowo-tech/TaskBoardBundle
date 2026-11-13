<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.23: the host's quotas. Called before each page is written (`$newTasks` = creates + restores of the page, and
 * since 1.29.0 tasks the provider reopens; a restored task that stays completed does not count) and before each file is
 * downloaded (`$newBytes`). Since 1.29.0 also when a task is restored by hand, when a move reopens a completed task
 * (`$newTasks` = 1) and when deleting a column would reopen its completed tasks (`$newTasks` = how many). File imports and
 * a provider's checklist item can reopen a task with no call. null = allowed.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see SyncQuotaPolicyInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface SyncLimitCheckerInterface
{
    public function check(TaskBoard $board, int $newTasks, int $newBytes): ?LimitRefusal;
}
