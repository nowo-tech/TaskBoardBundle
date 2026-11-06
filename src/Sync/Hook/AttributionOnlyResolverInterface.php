<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.28.0 (TB-SYNC-4), optional, on the host's {@see SyncPersonResolverInterface}: a candidate the host offers for
 * attribution only, such as a suspended member. A person mapped to them shows the user's label as creator, comment
 * author and on tracked time (the sink gets the user), and that is all: no task member row, not written as task creator
 * or comment author, no notification, no board access. The answer wins over the host's board access check.
 */
interface AttributionOnlyResolverInterface
{
    public function isAttributionOnly(TaskBoard $board, object $user): bool;
}
