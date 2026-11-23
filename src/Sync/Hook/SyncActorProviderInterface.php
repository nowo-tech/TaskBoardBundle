<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `SyncActorResolverInterface` (the 2.14 interface, removed in 3.0): who a scheduled,
 * console or queued run acts as, given the board as a {@see TaskBoardSnapshot}. null = refused (`no_actor`).
 *
 * Set `sync.actor_resolver` to a service implementing it.
 */
interface SyncActorProviderInterface
{
    public function actorFor(TaskBoardSnapshot $board, ?string $actorId = null): ?object;
}
