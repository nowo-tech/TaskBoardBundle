<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Member;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

/**
 * Since 2.18.0: the default {@see MemberBadgeProviderInterface}: no badge for anyone. Stateless.
 */
final readonly class NoMemberBadges implements MemberBadgeProviderInterface
{
    public function badgesFor(TaskBoardUserRef $member): array
    {
        return [];
    }

    public function badgesForMany(array $members): array
    {
        return [];
    }
}
