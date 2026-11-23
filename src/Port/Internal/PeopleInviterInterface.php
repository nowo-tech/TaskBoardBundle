<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;

/**
 * Since 1.27.0 (TB-SYNC-2, `sync.people.inviter`): optional host hook behind the People page's *Invite by e-mail*
 * option. The bundle never creates users: the host invites (or finds) someone by the remote person's e-mail.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see PeopleInvitationInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface PeopleInviterInterface
{
    /** Whether the actor may invite people to this board (false hides the option). */
    public function canInvite(TaskBoard $board, object $actor): bool;

    /**
     * Invites the person. Returns the host user when one exists right away and should be mapped (it must still be a
     * candidate of every board of the directory), or null when the invitation is pending: the person stays unmapped
     * and the automatic e-mail match maps them on a later sync once their account is a verified candidate.
     *
     * @param string $email the remote e-mail (untrusted: validate it before sending anything)
     * @param string $name the remote display name (untrusted text)
     */
    public function invite(TaskBoard $board, string $email, string $name, object $actor): ?object;
}
