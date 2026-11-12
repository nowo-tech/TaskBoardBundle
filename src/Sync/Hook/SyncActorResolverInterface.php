<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.23: who a scheduled, console or queued run acts as. null = refused (`no_actor`).
 *
 * @deprecated since 2.15.0, implement the framework-free {@see SyncActorProviderInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface SyncActorResolverInterface
{
    public function actorFor(TaskBoard $board, ?string $actorId = null): ?object;
}
