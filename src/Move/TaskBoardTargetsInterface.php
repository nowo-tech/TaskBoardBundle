<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.3.0 (spec 217, FR-003): `move.targets` — the boards a task may be moved or duplicated to. Only these are offered,
 * and any other target answers 404. The default ({@see DefaultTaskBoardTargets}) offers the active boards of the task's
 * tenant that the user may edit; a host narrows it (e.g. to boards of the same payer).
 *
 * @deprecated since 2.15.0, implement the framework-free {@see TaskBoardTargetIdsInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface TaskBoardTargetsInterface
{
    /**
     * @return list<TaskBoard> the source's board may be among them (a duplicate on the same board); a move leaves it out
     */
    public function targets(Task $task, object $user): array;
}
