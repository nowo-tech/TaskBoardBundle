<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientContextPolicyInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientPrincipalResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientRequest;
use SensitiveParameter;

/**
 * Since 3.4.0: the client API's ports of the DDD host (id user references), for the look-alike checks: one space for
 * the boards without a tenant, every plan allows tasks, no token resolves (the tests build the call themselves).
 */
final class DddClientHost implements TaskClientPrincipalResolverInterface, TaskClientContextPolicyInterface, TaskApiWorkspaceInterface, TaskApiPlanGateInterface
{
    public function resolve(#[SensitiveParameter] string $bearer, TaskClientRequest $request): ?TaskClientPrincipal
    {
        return null;
    }

    public function contextsFor(TaskClientPrincipal $principal): array
    {
        return [];
    }

    public function allows(TaskClientPrincipal $principal, string $contextKey): bool
    {
        return false;
    }

    public function whereFor(TaskClientPrincipal $principal, string $contextKey): TaskApiWhere
    {
        return TaskApiWhere::limitedTo([], []);
    }

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

    public function spaceOfTenant(TaskApiPrincipal $principal, ?string $tenantKey): TaskApiSpace
    {
        return new TaskApiSpace('space', 'Space', 'team', $tenantKey);
    }

    public function allowsTasks(object $user, ?string $tenantKey): bool
    {
        return true;
    }
}
