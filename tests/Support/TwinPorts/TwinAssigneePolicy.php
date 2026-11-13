<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneePolicyInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

final readonly class TwinAssigneePolicy implements TaskAssigneePolicyInterface
{
    public function __construct(private TaskAssigneeProviderInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function assignableUsers(TaskBoardSnapshot $board): array
    {
        return $this->legacy->assignableUsers($this->lookup->board($board->id));
    }

    public function canBeAssigned(TaskBoardSnapshot $board, object $user): bool
    {
        return $this->legacy->canBeAssigned($this->lookup->board($board->id), $user);
    }
}
