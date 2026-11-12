<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\PeopleInvitationInterface;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

final readonly class TwinPeopleInvitation implements PeopleInvitationInterface
{
    public function __construct(private PeopleInviterInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function canInvite(TaskBoardSnapshot $board, object $actor): bool
    {
        return $this->legacy->canInvite($this->lookup->board($board->id), $actor);
    }

    public function invite(TaskBoardSnapshot $board, string $email, string $name, object $actor): ?object
    {
        return $this->legacy->invite($this->lookup->board($board->id), $email, $name, $actor);
    }
}
