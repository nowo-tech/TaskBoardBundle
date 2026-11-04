<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use Nowo\TaskBoardBundle\Sync\Hook\SyncRunnerInterface;

/**
 * Since 1.23: what {@see SyncRunnerInterface} is asked to run — ids and flags only,
 * never a credential, so it can travel in a Messenger message.
 */
final readonly class SyncRequest
{
    public function __construct(
        public string $boardId,
        public ?string $actorId,
        public SyncTrigger $trigger = SyncTrigger::Manual,
        public bool $full = false,
        public bool $dryRun = false,
        public bool $allowMassArchive = false,
    ) {
    }
}
