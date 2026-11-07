<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskCommentPolicy;
use Stringable;

use function array_filter;
use function array_map;
use function array_values;
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
        $this->requirePlanAndLevel($principal, $board->getTenantKey(), $capability);

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
        $this->requirePlanAndLevel($principal, $task->getBoard()->getTenantKey(), $capability);

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
     * A space the token reaches: the host answers for it, and where names its tenant or one of its boards. Reads only.
     *
     * @throws TaskApiRefusal
     */
    public function space(TaskApiPrincipal $principal, string $spaceId, TaskApiCapability $capability): TaskApiSpace
    {
        $space = $this->workspace->space($principal, $spaceId);
        if (!$space instanceof TaskApiSpace || !$this->touches($principal, $space)) {
            throw TaskApiRefusal::notFound();
        }
        $this->requirePlanAndLevel($principal, $space->tenantKey, $capability);

        return $space;
    }

    /**
     * Whether a space of the host may be listed for the token: where names its tenant or one of its boards, and its plan holds
     * the module. A list leaves the others out, without a word.
     */
    public function lists(TaskApiPrincipal $principal, TaskApiSpace $space): bool
    {
        return $this->touches($principal, $space) && $this->planGate->allowsTasks($principal->user, $space->tenantKey);
    }

    /**
     * The active boards of a tenant the token reaches and the person may view, in the host's order.
     *
     * @return list<TaskBoard>
     */
    public function visibleBoards(TaskApiPrincipal $principal, ?string $tenantKey): array
    {
        $visible = [];
        foreach ($this->boardsOfTenant($tenantKey) as $board) {
            if ($principal->where->reachesBoard($board) && $this->access->canViewBoard($principal->user, $board)) {
                $visible[] = $board;
            }
        }

        return $visible;
    }

    /**
     * @throws TaskApiRefusal
     */
    public function requireLevel(TaskApiPrincipal $principal, TaskApiCapability $capability): void
    {
        if (!$capability->allows($principal->level)) {
            throw TaskApiRefusal::levelRefused();
        }
    }

    private function touches(TaskApiPrincipal $principal, TaskApiSpace $space): bool
    {
        return $principal->where->touchesTenant($space->tenantKey, array_map(static fn (TaskBoard $board): string => $board->getId(), $this->boardsOfTenant($space->tenantKey)));
    }

    /**
     * @return list<TaskBoard>
     */
    private function boardsOfTenant(?string $tenantKey): array
    {
        if ($tenantKey !== null) {
            return $this->boards->findActiveByTenant($tenantKey);
        }

        return array_values(array_filter($this->boards->findAllActive(), static fn (TaskBoard $board): bool => $board->getTenantKey() === null));
    }

    /**
     * @throws TaskApiRefusal
     */
    private function requirePlanAndLevel(TaskApiPrincipal $principal, ?string $tenantKey, TaskApiCapability $capability): void
    {
        if (!$this->planGate->allowsTasks($principal->user, $tenantKey)) {
            throw TaskApiRefusal::planRefused();
        }
        $this->requireLevel($principal, $capability);
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
