<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;

/**
 * Since 2.2.0 (spec 213): how the host's accounts and tenants read as ClickUp's teams and spaces, for one person. A tenant
 * the host does not answer for is out of reach. Default: {@see EmptyTaskApiWorkspace}, so every board answers 404.
 */
interface TaskApiWorkspaceInterface
{
    /**
     * @return list<TaskApiTeam>
     */
    public function teams(TaskApiPrincipal $principal): array;

    /**
     * @return list<TaskApiSpace>
     */
    public function spaces(TaskApiPrincipal $principal, string $teamId): array;

    public function space(TaskApiPrincipal $principal, string $spaceId): ?TaskApiSpace;

    public function spaceOfTenant(TaskApiPrincipal $principal, ?string $tenantKey): ?TaskApiSpace;
}
