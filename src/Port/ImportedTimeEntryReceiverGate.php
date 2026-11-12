<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntryReceiverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;

/**
 * Since 2.15.0: asks a host {@see ImportedTimeEntryReceiverInterface} where the bundle asks the deprecated
 * {@see ImportedTimeEntrySinkInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class ImportedTimeEntryReceiverGate implements ImportedTimeEntrySinkInterface
{
    public function __construct(private ImportedTimeEntryReceiverInterface $receiver)
    {
    }

    public function accept(TaskBoard $board, TaskSyncDirectory $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void
    {
        $this->receiver->accept(Snapshots::board($board), Snapshots::directory($directory), $window, $access, $entries, $windowComplete);
    }
}
