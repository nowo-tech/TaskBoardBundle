<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;

/**
 * Since 2.15.0: asks a host {@see PeopleInvitationInterface} where the bundle asks the deprecated
 * {@see PeopleInviterInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class PeopleInvitationGate implements PeopleInviterInterface
{
    public function __construct(private PeopleInvitationInterface $invitation)
    {
    }

    public function canInvite(TaskBoard $board, object $actor): bool
    {
        return $this->invitation->canInvite(Snapshots::board($board), $actor);
    }

    public function invite(TaskBoard $board, string $email, string $name, object $actor): ?object
    {
        return $this->invitation->invite(Snapshots::board($board), $email, $name, $actor);
    }
}
