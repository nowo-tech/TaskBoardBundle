<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;

/**
 * Since 2.15.0: asks a host {@see TaskBoardDefaultViewPolicyInterface} where the bundle asks the deprecated {@see TaskBoardDefaultViewResolverInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskBoardDefaultViewGate implements TaskBoardDefaultViewResolverInterface
{
    public function __construct(private TaskBoardDefaultViewPolicyInterface $policy)
    {
    }

    public function defaultView(object $user, TaskBoard $board): ?string
    {
        return $this->policy->defaultView($user, Snapshots::board($board));
    }
}
