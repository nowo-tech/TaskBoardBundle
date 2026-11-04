<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Dto\SyncRequest;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;

/**
 * Since 1.23: starts a run requested from the UI (Beacon: a Messenger message whose handler calls
 * {@see TaskBoardSyncService::runLinked()}).
 */
interface SyncRunnerInterface
{
    public function dispatch(SyncRequest $request): void;

    /**
     * Whether the progress page should advance the run itself, one slice per poll (the inline runner); false when a
     * worker runs it.
     */
    public function advancesOnPoll(): bool;
}
