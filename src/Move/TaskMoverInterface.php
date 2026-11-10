<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;

/**
 * Since 2.11.0 (DDD audit G4): the port of {@see TaskMover}, which moves a task and its subtasks to another board in one
 * transaction, exactly as its {@see TaskMovePlan} said (TASK_BOARD_CHANGED after the commit). A host's application layer
 * depends on it and can double or decorate it; the container aliases it to the bundle's class. The bundle's own controller
 * keeps using the class, so a host decorator wraps the host's calls only.
 *
 * No access check (audit JY-L2): like the class, it trusts its caller. It refuses only a stale plan, a provider run in
 * progress on either board and the host's quota on the open tasks that arrive. Before calling it the host checks what the
 * bundle's controller checks: `TaskBoardResourceAccessChecker::canViewTask()` / `canEditTask()` on the task and
 * `canEditBoard()` on its board, that the target is one of `TaskBoardTargetsInterface::targets()` (not archived, and
 * `canEditBoard()` again), that the task's board belongs to the current tenant, and CSRF.
 */
interface TaskMoverInterface
{
    /**
     * @param array<string, string> $columnMap the map the plan was made with
     *
     * @throws TaskCopyRefusedException before anything is written
     */
    public function move(Task $task, TaskBoard $target, array $columnMap, string $fingerprint, object $actor): TaskMovePlan;
}
