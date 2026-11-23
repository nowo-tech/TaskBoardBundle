<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyPolicyInterface;

/**
 * Since 1.28.0 (TB-SYNC-4), optional, on the host's {@see SyncPersonResolverInterface}: a candidate the host offers for
 * attribution only, such as a suspended member. A person mapped to them shows the user's label as creator, comment
 * author and on tracked time (the sink gets the user), and that is all: no task member row, not written as task creator
 * or comment author, no notification, no board access. The answer wins over the host's board access check.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see AttributionOnlyPolicyInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface AttributionOnlyResolverInterface
{
    public function isAttributionOnly(TaskBoard $board, object $user): bool;
}
