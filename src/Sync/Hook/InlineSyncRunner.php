<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncRequest;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;

/**
 * Since 1.23: the default runner — runs one slice in the request (`slicePages` task pages) and lets the progress
 * page advance the run, one slice per poll. Hosts with a worker replace it (Beacon: Messenger).
 */
final readonly class InlineSyncRunner implements SyncRunnerInterface
{
    public function __construct(
        private TaskBoardSyncService $sync,
        private TaskBoardRepositoryInterface $boards,
        private SyncActorResolverInterface $actors,
        private int $slicePages = 5,
    ) {
    }

    public function dispatch(SyncRequest $request): void
    {
        $board = $this->boards->findById($request->boardId);
        $actor = $board instanceof TaskBoard ? $this->actors->actorFor($board, $request->actorId) : null;
        if (!$board instanceof TaskBoard || $actor === null) {
            return;
        }
        $this->sync->runLinked($board, new SyncOptions(
            actor: $actor,
            trigger: $request->trigger,
            full: $request->full,
            dryRun: $request->dryRun,
            allowMassArchive: $request->allowMassArchive,
            maxPages: $this->slicePages,
        ));
    }

    public function advancesOnPoll(): bool
    {
        return true;
    }
}
