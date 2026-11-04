<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/** Since 1.23: no host quota (the bundle's own caps still apply). */
final readonly class NullSyncLimitChecker implements SyncLimitCheckerInterface
{
    public function check(TaskBoard $board, int $newTasks, int $newBytes): ?LimitRefusal
    {
        return null;
    }
}
