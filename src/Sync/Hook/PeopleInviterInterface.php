<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.27.0 (TB-SYNC-2, `sync.people.inviter`): optional host hook behind the People page's *Invite by e-mail*
 * option. The bundle never creates users: the host invites (or finds) someone by the remote person's e-mail.
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
