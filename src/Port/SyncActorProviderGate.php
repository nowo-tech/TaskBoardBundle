<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;

/**
 * Since 2.15.0: asks a host {@see SyncActorProviderInterface} where the bundle asks the deprecated
 * {@see SyncActorResolverInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class SyncActorProviderGate implements SyncActorResolverInterface
{
    public function __construct(private SyncActorProviderInterface $provider)
    {
    }

    public function actorFor(TaskBoard $board, ?string $actorId = null): ?object
    {
        return $this->provider->actorFor(Snapshots::board($board), $actorId);
    }
}
