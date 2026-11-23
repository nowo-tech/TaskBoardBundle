<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Port\Internal\TaskMentionCandidatesResolverInterface;

/**
 * Since 2.15.0: asks a host {@see TaskMentionCandidatesProviderInterface} where the bundle asks its internal seam {@see TaskMentionCandidatesResolverInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskMentionCandidatesGate implements TaskMentionCandidatesResolverInterface
{
    public function __construct(private TaskMentionCandidatesProviderInterface $provider)
    {
    }

    public function candidates(Task $task): array
    {
        return $this->provider->candidates(Snapshots::task($task));
    }
}
