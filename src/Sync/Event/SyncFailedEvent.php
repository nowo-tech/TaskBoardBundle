<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Symfony\Contracts\EventDispatcher\Event;

/** Since 1.23: {@see TaskBoardEvents::SYNC_FAILED}. */
final class SyncFailedEvent extends Event
{
    public function __construct(
        public readonly TaskBoard $board,
        public readonly SyncError $error,
        public readonly SyncReport $report,
    ) {
    }
}
