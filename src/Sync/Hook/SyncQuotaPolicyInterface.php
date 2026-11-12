<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see SyncLimitCheckerInterface}: the host's quotas,
 * asked at the same moments, with the board as a {@see TaskBoardSnapshot}. null = allowed.
 *
 * Set `sync.limit_checker` to a service implementing it. A service that also implements the deprecated interface is
 * asked through that one until 3.0.
 */
interface SyncQuotaPolicyInterface
{
    public function check(TaskBoardSnapshot $board, int $newTasks, int $newBytes): ?LimitRefusal;
}
