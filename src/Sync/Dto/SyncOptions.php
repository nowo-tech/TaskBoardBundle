<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use DateTimeImmutable;

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
        /** Since 1.25: refresh the people directory and resolve people through it (when the provider has people). */
        public bool $people = true,
        /** Since 1.25: a first import is refused (`people_mapping_required`) until the People step was saved. */
        public bool $requirePeopleMapping = false,
        /** Since 1.25: run the `time_entries` phase (needs `sync.time_entries.sink`). */
        public bool $timeEntries = false,
        /** Since 1.25: start of the first time window (null = the board's oldest task). */
        public ?DateTimeImmutable $timeFrom = null,
        /** Since 1.25: member token only — read the legacy per-task intervals (explicit action, never automatic). */
        public bool $timeFallbackPerTask = false,
        /** Since 1.25: import watchers in this run (also needs `sync.watchers.enabled` and the provider's data). */
        public bool $watchers = true,
        /** Since 1.25: import checklists as subtasks in this run (also needs `sync.checklists.enabled`). */
        public bool $checklists = true,
    ) {
    }
}
