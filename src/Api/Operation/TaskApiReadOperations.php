<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;

/**
 * Since 2.2.0 (spec 213): what the API reads, without HTTP: each method takes the principal and applies every check.
 */
final readonly class TaskApiReadOperations
{
    public function __construct(
        private TaskApiPlanGateInterface $planGate,
        private TaskApiWorkspaceInterface $workspace,
        private TaskApiUsersInterface $users,
    ) {
    }

    /**
     * @throws TaskApiRefusal
     */
    public function me(TaskApiPrincipal $principal): TaskApiUser
    {
        $this->requireIdentity($principal);

        return $this->users->profiles([$principal->user], $principal)[$this->users->apiId($principal->user)] ?? throw TaskApiRefusal::notFound();
    }

    /**
     * @throws TaskApiRefusal
     *
     * @return list<TaskApiTeam>
     */
    public function teams(TaskApiPrincipal $principal): array
    {
        $this->requireIdentity($principal);

        return $this->workspace->teams($principal);
    }

    /**
     * Every level may read who it is, so only the plan is asked.
     *
     * @throws TaskApiRefusal
     */
    private function requireIdentity(TaskApiPrincipal $principal): void
    {
        if (!$this->planGate->allowsTasks($principal->user, null)) {
            throw TaskApiRefusal::planRefused();
        }
    }
}
