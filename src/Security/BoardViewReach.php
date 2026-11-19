<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Since 2.25.0 (header search): what the default board rule of {@see TaskBoardResourceAccessChecker::canViewBoard()}
 * opens to one user, read once: every board (resource scoping off, or a `security.manage_roles` holder), else the boards
 * they created and the boards of `$teamIds`. `$listenersMayGrant` says whether a BOARD_ACCESS_CHECK listener could open
 * a board beyond that (then the default may only order a search, never filter it). Plain values for one call.
 */
final readonly class BoardViewReach
{
    /**
     * @param list<string> $teamIds the user's team ids (membership resolver and TeamMember rows)
     */
    public function __construct(
        public bool $all,
        public array $teamIds = [],
        public bool $listenersMayGrant = true,
    ) {
    }
}
