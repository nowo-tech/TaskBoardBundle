<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;

/**
 * Since 2.2.0 (spec 213): the default workspace: nothing to reach, until the host sets `api.hooks.workspace`.
 */
final readonly class EmptyTaskApiWorkspace implements TaskApiWorkspaceInterface
{
    public function teams(TaskApiPrincipal $principal): array
    {
        return [];
    }

    public function spaces(TaskApiPrincipal $principal, string $teamId): array
    {
        return [];
    }

    public function space(TaskApiPrincipal $principal, string $spaceId): ?TaskApiSpace
    {
        return null;
    }

    public function spaceOfTenant(TaskApiPrincipal $principal, ?string $tenantKey): ?TaskApiSpace
    {
        return null;
    }
}
