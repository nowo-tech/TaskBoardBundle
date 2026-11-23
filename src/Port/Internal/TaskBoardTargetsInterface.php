<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;

/**
 * Since 2.3.0 (spec 217, FR-003): `move.targets` — the boards a task may be moved or duplicated to. Only these are offered,
 * and any other target answers 404. The default ({@see DefaultTaskBoardTargets}) offers the active boards of the task's
 * tenant that the user may edit; a host narrows it (e.g. to boards of the same payer).
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskBoardTargetIdsInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskBoardTargetsInterface
{
    /**
     * @return list<TaskBoard> the source's board may be among them (a duplicate on the same board); a move leaves it out
     */
    public function targets(Task $task, object $user): array;
}
