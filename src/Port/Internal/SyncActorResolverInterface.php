<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;

/**
 * Since 1.23: who a scheduled, console or queued run acts as. null = refused (`no_actor`).
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see SyncActorProviderInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface SyncActorResolverInterface
{
    public function actorFor(TaskBoard $board, ?string $actorId = null): ?object;
}
