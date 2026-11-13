<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;

/**
 * Since 2.15.0: asks a host {@see SyncQuotaPolicyInterface} where the bundle asks the deprecated
 * {@see SyncLimitCheckerInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class SyncQuotaPolicyGate implements SyncLimitCheckerInterface
{
    public function __construct(private SyncQuotaPolicyInterface $policy)
    {
    }

    public function check(TaskBoard $board, int $newTasks, int $newBytes): ?LimitRefusal
    {
        return $this->policy->check(Snapshots::board($board), $newTasks, $newBytes);
    }
}
