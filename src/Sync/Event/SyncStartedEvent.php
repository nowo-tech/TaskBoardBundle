<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Symfony\Contracts\EventDispatcher\Event;

/** Since 1.23: {@see TaskBoardEvents::SYNC_STARTED} (also when a run resumes). */
final class SyncStartedEvent extends Event
{
    public function __construct(
        public readonly TaskBoard $board,
        public readonly string $runId,
        public readonly string $provider,
        public readonly SyncMode $mode,
        public readonly SyncTrigger $trigger,
        public readonly object $actor,
        public readonly bool $dryRun,
    ) {
    }
}
