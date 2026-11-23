<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `SyncLimitCheckerInterface` (the 2.14 interface, removed in 3.0): the host's quotas,
 * asked at the same moments, with the board as a {@see TaskBoardSnapshot}. null = allowed.
 *
 * Set `sync.limit_checker` to a service implementing it.
 */
interface SyncQuotaPolicyInterface
{
    public function check(TaskBoardSnapshot $board, int $newTasks, int $newBytes): ?LimitRefusal;
}
