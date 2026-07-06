<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Resolves team membership IDs for a user (optional host integration).
 */
interface TaskBoardTeamMembershipResolverInterface
{
    /** @return list<int|string> */
    public function resolveTeamIds(object $user): array;
}
