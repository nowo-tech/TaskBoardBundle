<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.23: the host's quotas. Called before each page is written (`$newTasks` = creates + restores of the page)
 * and before each file is downloaded (`$newBytes`). null = allowed.
 */
interface SyncLimitCheckerInterface
{
    public function check(TaskBoard $board, int $newTasks, int $newBytes): ?LimitRefusal;
}
