<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncQuotaPolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

final readonly class TwinQuotaPolicy implements SyncQuotaPolicyInterface
{
    public function __construct(private SyncLimitCheckerInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function check(TaskBoardSnapshot $board, int $newTasks, int $newBytes): ?LimitRefusal
    {
        return $this->legacy->check($this->lookup->board($board->id), $newTasks, $newBytes);
    }
}
