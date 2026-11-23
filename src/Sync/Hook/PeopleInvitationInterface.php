<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `PeopleInviterInterface` (the 2.14 interface, removed in 3.0), given the board as a
 * {@see TaskBoardSnapshot}. The bundle never creates users: the host invites (or finds) someone by e-mail.
 *
 * Set `sync.people.inviter` to a service implementing it.
 */
interface PeopleInvitationInterface
{
    /** Whether the actor may invite people to this board (false hides the option). */
    public function canInvite(TaskBoardSnapshot $board, object $actor): bool;

    /**
     * Invites the person; returns the host user when one exists right away and should be mapped, or null while the
     * invitation is pending. `$email` and `$name` are untrusted remote values.
     */
    public function invite(TaskBoardSnapshot $board, string $email, string $name, object $actor): ?object;
}
