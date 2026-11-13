<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;

/**
 * Since 2.16.0 (spec 234): the default: a team lists no members, as before 2.16, until the host sets `api.hooks.members`.
 */
final readonly class EmptyTaskApiMembers implements TaskApiMembersInterface
{
    public function members(TaskApiPrincipal $viewer, string $teamId): array
    {
        return [];
    }
}
