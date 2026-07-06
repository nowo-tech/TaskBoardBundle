<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Null-object team resolver when the host app has no team model.
 */
final class NullTaskBoardTeamMembershipResolver implements TaskBoardTeamMembershipResolverInterface
{
    /** @return list<int|string> */
    public function resolveTeamIds(object $user): array
    {
        return [];
    }
}
