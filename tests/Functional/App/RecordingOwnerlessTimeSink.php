<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Hook\OwnerlessTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Time\TimeWindow;

/** Since 1.28.0: a sink that keeps the hours of people with no user; records into {@see RecordingTimeSink::$calls}. */
final class RecordingOwnerlessTimeSink implements OwnerlessTimeEntrySinkInterface
{
    public function accept(TaskBoard $board, TaskSyncDirectory $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $windowComplete): void
    {
        (new RecordingTimeSink())->accept($board, $directory, $window, $access, $entries, $windowComplete);
    }
}
