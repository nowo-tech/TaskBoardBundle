<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/**
 * Since 1.23: the options of one run.
 */
final readonly class SyncOptions
{
    /**
     * @param object $actor the user the writes, the change history and the run are attributed to
     * @param int|null $maxPages a time slice: stop with status Paused after this many task pages
     * @param int|null $timeBudgetSeconds idem, checked between pages
     */
    public function __construct(
        public object $actor,
        public SyncTrigger $trigger = SyncTrigger::Manual,
        public bool $full = false,
        public bool $dryRun = false,
        public bool $createMissingColumns = true,
        public ?string $fallbackColumnId = null,
        public bool $importComments = true,
        public bool $importAttachments = true,
        public bool $archiveRemoteDeletions = true,
        public bool $allowMassArchive = false,
        public ?int $maxPages = null,
        public ?int $timeBudgetSeconds = null,
    ) {
    }
}
