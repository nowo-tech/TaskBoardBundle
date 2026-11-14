<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Member;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

/**
 * Since 2.19.0 (Beacon spec 234): the badges a host shows next to a member's name — in the history, the comments, the
 * task's assignees and watchers. Configure it with `member_badge_provider`; without one no badge is shown and the pages
 * render exactly as before.
 *
 * Framework-free: members come as {@see TaskBoardUserRef} (the user's id, as `getId()` gives it), badges go back as
 * {@see MemberBadge} values (plain text, an allow-listed variant). The bundle asks {@see self::badgesForMany()} once
 * for the people a page shows and keeps the answer for that request only; {@see self::badgesFor()} is for a single
 * member. Must not run a query per member. Keep no state across requests (FrankenPHP worker mode).
 */
interface MemberBadgeProviderInterface
{
    /**
     * @return list<MemberBadge>
     */
    public function badgesFor(TaskBoardUserRef $member): array;

    /**
     * @param list<TaskBoardUserRef> $members distinct ids, never empty
     *
     * @return array<string, list<MemberBadge>> by member id; a missing id has no badge
     */
    public function badgesForMany(array $members): array;
}
