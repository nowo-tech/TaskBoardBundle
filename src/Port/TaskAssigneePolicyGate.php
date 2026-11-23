<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Internal\TaskAssigneeProviderInterface;

/**
 * Since 2.15.0: asks a host {@see TaskAssigneePolicyInterface} where the bundle asks its internal seam {@see TaskAssigneeProviderInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskAssigneePolicyGate implements TaskAssigneeProviderInterface
{
    public function __construct(private TaskAssigneePolicyInterface $policy)
    {
    }

    public function assignableUsers(TaskBoard $board): array
    {
        return $this->policy->assignableUsers(Snapshots::board($board));
    }

    public function canBeAssigned(TaskBoard $board, object $user): bool
    {
        return $this->policy->canBeAssigned(Snapshots::board($board), $user);
    }
}
