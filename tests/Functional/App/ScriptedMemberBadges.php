<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Member\MemberBadge;
use Nowo\TaskBoardBundle\Member\MemberBadgeProviderInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

use function array_map;

/**
 * Since 2.19.0 (Beacon spec 234): the host's member badges in the functional kernels — the badges a test maps to a
 * user id; records every call (to prove one call per page and no state across requests).
 */
final class ScriptedMemberBadges implements MemberBadgeProviderInterface
{
    /** @var array<string, list<MemberBadge>> by user id */
    public static array $badges = [];

    /** @var list<list<string>> the ids of each badgesForMany() call */
    public static array $batches = [];

    public static int $single = 0;

    public function badgesFor(TaskBoardUserRef $member): array
    {
        ++self::$single;

        return self::$badges[$member->id] ?? [];
    }

    public function badgesForMany(array $members): array
    {
        self::$batches[] = array_map(static fn (TaskBoardUserRef $member): string => $member->id, $members);
        $answer          = [];
        foreach ($members as $member) {
            $answer[$member->id] = self::$badges[$member->id] ?? [];
        }

        return $answer;
    }

    public static function reset(): void
    {
        self::$badges  = [];
        self::$batches = [];
        self::$single  = 0;
    }
}
