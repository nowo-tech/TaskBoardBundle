<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

final readonly class TwinTargetIds implements TaskBoardTargetIdsInterface
{
    public function __construct(private TaskBoardTargetsInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function targetIds(TaskSnapshot $task, object $user): array
    {
        return array_map(static fn (TaskBoard $board): string => $board->getId(), $this->legacy->targets($this->lookup->task($task->id), $user));
    }
}
