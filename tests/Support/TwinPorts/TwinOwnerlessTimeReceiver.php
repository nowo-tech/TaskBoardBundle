<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSyncDirectorySnapshot;

final readonly class TwinOwnerlessTimeReceiver implements OwnerlessTimeEntryReceiverInterface
{
    public function __construct(private ImportedTimeEntrySinkInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function accept(TaskBoardSnapshot $board, TaskSyncDirectorySnapshot $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void
    {
        $this->legacy->accept($this->lookup->board($board->id), $this->lookup->directory($directory->id), $window, $access, $entries, $windowComplete);
    }
}
