<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskCommentPolicy;
use Stringable;

use function is_scalar;
use function method_exists;

/**
 * Since 2.2.0 (spec 213): every id-based target of the API goes through here, on every request: level ∩ the person's access
 * ∩ where, deny by default. In this order, and the first failure answers:
 *
 * 1. out of reach (missing, outside where, no space for its tenant, no view access, for My tasks not its own): 404, always the same;
 * 2. the tasks module not in the plan: 403 `PLAN_001`;
 * 3. the level may not: 403 `TOKEN_001`;
 * 4. the person may not: 403 `ACCESS_002`.
 */
final readonly class TaskApiGuard
{
    public function __construct(
        private TaskBoardRepositoryInterface $boards,
        private TaskRepositoryInterface $tasks,
        private TaskBoardResourceAccessChecker $access,
        private TaskCommentPolicy $comments,
        private TaskApiWorkspaceInterface $workspace,
        private TaskApiPlanGateInterface $planGate,
    ) {
    }

    /**
     * @throws TaskApiRefusal
     */
    public function board(TaskApiPrincipal $principal, string $boardId, TaskApiCapability $capability): TaskBoard
    {
        $board = $this->boards->findById($boardId);
        if (!$board instanceof TaskBoard || !$this->reachesBoard($principal, $board)) {
            throw TaskApiRefusal::notFound();
        }
        $this->requirePlanAndLevel($principal, $board, $capability);

        $allowed = match ($capability) {
            TaskApiCapability::ReadOwn, TaskApiCapability::ReadEverything => true,
            TaskApiCapability::EditTasks                                  => $this->access->canEditBoard($principal->user, $board),
            TaskApiCapability::ManageBoards                               => $this->access->canManageBoard($principal->user, $board),
            default                                                       => false, // asked of a task, not of a board
        };
        if (!$allowed) {
            throw TaskApiRefusal::permissionRefused();
        }

        return $board;
    }

    /**
     * @throws TaskApiRefusal
     */
    public function task(TaskApiPrincipal $principal, string $taskId, TaskApiCapability $capability): Task
    {
        $task = $this->tasks->findById($taskId);
        if (
            !$task instanceof Task
            || !$this->reachesBoard($principal, $task->getBoard())
            || !$this->access->canViewTask($principal->user, $task)
            || ($principal->level === TaskApiLevel::MyTasks && !$this->isOwn($principal->user, $task))
        ) {
            throw TaskApiRefusal::notFound();
        }
        $this->requirePlanAndLevel($principal, $task->getBoard(), $capability);

        $allowed = match ($capability) {
            TaskApiCapability::ReadOwn, TaskApiCapability::ReadEverything  => true,
            TaskApiCapability::WorkOnTask, TaskApiCapability::EditTasks    => $this->access->canEditTask($principal->user, $task),
            TaskApiCapability::Comment                                     => $this->comments->mayComment($principal->user, $task),
            TaskApiCapability::DeleteTask, TaskApiCapability::ManageBoards => $this->access->canManageBoard($principal->user, $task->getBoard()),
        };
        if (!$allowed) {
            throw TaskApiRefusal::permissionRefused();
        }

        return $task;
    }

    /**
     * "My tasks" (spec): assigned to the person or created by them. Watching, reviewing or being a stakeholder does not count.
     */
    public function isOwn(object $user, Task $task): bool
    {
        if ($this->sameUser($user, $task->getCreator())) {
            return true;
        }
        foreach ($task->getMembers() as $member) {
            if ($member->getMemberRole() === TaskMemberRole::Assignee && $this->sameUser($user, $member->getUser())) {
                return true;
            }
        }

        return false;
    }

    private function reachesBoard(TaskApiPrincipal $principal, TaskBoard $board): bool
    {
        return $principal->where->reachesBoard($board)
            && $this->workspace->spaceOfTenant($principal, $board->getTenantKey()) !== null
            && $this->access->canViewBoard($principal->user, $board);
    }

    /**
     * @throws TaskApiRefusal
     */
    private function requirePlanAndLevel(TaskApiPrincipal $principal, TaskBoard $board, TaskApiCapability $capability): void
    {
        if (!$this->planGate->allowsTasks($principal->user, $board->getTenantKey())) {
            throw TaskApiRefusal::planRefused();
        }
        if (!$capability->allows($principal->level)) {
            throw TaskApiRefusal::levelRefused();
        }
    }

    private function sameUser(object $user, ?object $other): bool
    {
        if ($other === null) {
            return false; // an erased account is nobody
        }
        if ($user === $other) {
            return true;
        }
        $id      = method_exists($user, 'getId') ? $user->getId() : null;
        $otherId = method_exists($other, 'getId') ? $other->getId() : null;

        return (is_scalar($id) || $id instanceof Stringable) && (is_scalar($otherId) || $otherId instanceof Stringable) && (string) $id === (string) $otherId;
    }
}
