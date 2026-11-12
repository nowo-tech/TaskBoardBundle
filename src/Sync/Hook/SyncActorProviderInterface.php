<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see SyncActorResolverInterface}: who a scheduled,
 * console or queued run acts as, given the board as a {@see TaskBoardSnapshot}. null = refused (`no_actor`).
 *
 * Set `sync.actor_resolver` to a service implementing it. A service that also implements the deprecated interface is
 * asked through that one until 3.0.
 */
interface SyncActorProviderInterface
{
    public function actorFor(TaskBoardSnapshot $board, ?string $actorId = null): ?object;
}
