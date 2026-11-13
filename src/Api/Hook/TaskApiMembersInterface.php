<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;

/**
 * Since 2.16.0 (spec 234, FR-025): who the members of a team (the host's account) are, for `GET /v2/team` and
 * `GET /v2/team/{id}/member`. The bundle asks only for a team the viewer's workspace hook lists
 * ({@see TaskApiWorkspaceInterface::teams()}); any other team is 404 before the hook is called. Ids are the API ids of
 * {@see TaskApiUsersInterface::apiId()}. Framework-free (only bundle value objects); keep no state between calls.
 * Default: {@see EmptyTaskApiMembers} (`members: []`, as before 2.16).
 */
interface TaskApiMembersInterface
{
    /**
     * @return list<TaskApiMember>
     */
    public function members(TaskApiPrincipal $viewer, string $teamId): array;
}
