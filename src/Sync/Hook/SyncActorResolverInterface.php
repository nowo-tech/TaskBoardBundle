<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.23: who a scheduled, console or queued run acts as. null = refused (`no_actor`).
 */
interface SyncActorResolverInterface
{
    public function actorFor(TaskBoard $board, ?string $actorId = null): ?object;
}
