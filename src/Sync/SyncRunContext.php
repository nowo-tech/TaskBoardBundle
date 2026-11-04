<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Dto\FileCounts;
use Nowo\TaskBoardBundle\Sync\Dto\SkipCounts;
use Nowo\TaskBoardBundle\Sync\Dto\SyncConflict;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncPhase;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;

/**
 * @internal since 1.23: the mutable state of one run slice (the service itself is stateless)
 */
final class SyncRunContext
{
    public int $created                      = 0;
    public int $updated                      = 0;
    public int $unchanged                    = 0;
    public int $archived                     = 0;
    public int $restored                     = 0;
    public int $columnsCreated               = 0;
    public int $dependenciesCreated          = 0;
    public int $commentsCreated              = 0;
    public int $commentsUpdated              = 0;
    public int $skipReadOnly                 = 0;
    public int $skipElsewhere                = 0;
    public int $skipLocallyDeleted           = 0;
    public int $skipLocallyArchived          = 0;
    public int $skipRepeated                 = 0;
    public int $filesCopied                  = 0;
    public int $filesReused                  = 0;
    public int $filesRefused                 = 0;
    public int $bytes                        = 0;
    public int $pagesDone                    = 0;
    public int $tasksSeen                    = 0;
    public bool $filesBlocked                = false;
    public ?string $refusalCode              = null;
    public ?int $retryAfter                  = null;
    public ?int $total                       = null;
    public ?string $providerCursor           = null;
    public SyncPhase $phase                  = SyncPhase::Tasks;
    public ?DateTimeImmutable $highWatermark = null;

    /** @var list<SyncConflict> */
    public array $conflicts = [];

    /** @var list<SyncError> */
    public array $warnings = [];

    /** @var list<SyncError> */
    public array $errors = [];

    /** @var array<string, true> external ids seen by this slice */
    public array $seen = [];

    /** @var list<array{0: string, 1: string, 2: string}> [task id, other external id, type or "parent"] */
    public array $pending = [];

    /** @var list<array{0: string, 1: string, 2: string}> [event, task id, external id] dispatched after commit */
    public array $taskEvents = [];

    /** @var array<string, StoredAttachment> inline images stored by this slice, by task id and key (not yet flushed) */
    public array $images = [];

    /** @var array<string, int> highest position per column id ('' = no column) */
    public array $positions = [];

    public function __construct(
        public string $runId,
        public TaskBoard $board,
        public TaskBoardConnection $connection,
        public TaskProviderInterface $provider,
        public ProviderCredential $credential,
        public SyncOptions $options,
        public object $actor,
        public SyncMode $mode,
        public ?DateTimeImmutable $updatedSince,
        public DateTimeImmutable $startedAt,
        public string $scope,
        public int $budgetBytes,
    ) {
    }

    /**
     * Counters carried over from the earlier slices of the same run.
     */
    public function resumeFrom(SyncReport $previous): void
    {
        $this->created             = $previous->created;
        $this->updated             = $previous->updated;
        $this->unchanged           = $previous->unchanged;
        $this->archived            = $previous->archived;
        $this->restored            = $previous->restored;
        $this->columnsCreated      = $previous->columnsCreated;
        $this->dependenciesCreated = $previous->dependenciesCreated;
        $this->commentsCreated     = $previous->commentsCreated;
        $this->commentsUpdated     = $previous->commentsUpdated;
        $this->skipReadOnly        = $previous->skips->readOnly;
        $this->skipElsewhere       = $previous->skips->elsewhere;
        $this->skipLocallyDeleted  = $previous->skips->locallyDeleted;
        $this->skipLocallyArchived = $previous->skips->locallyArchived;
        $this->skipRepeated        = $previous->skips->repeated;
        $this->filesCopied         = $previous->files->copied;
        $this->filesReused         = $previous->files->reused;
        $this->filesRefused        = $previous->files->refused;
        $this->bytes               = $previous->files->bytes;
        $this->conflicts           = $previous->conflicts;
        $this->warnings            = $previous->warnings;
        $this->total               = $previous->total;
        $this->startedAt           = $previous->startedAt;
    }

    /**
     * @param array<string, string> $params
     */
    public function warn(string $code, array $params = []): void
    {
        foreach ($this->warnings as $warning) {
            if ($warning->code === $code && $warning->params === $params) {
                return;
            }
        }
        $this->warnings[] = new SyncError($code, $params);
    }

    public function cursor(): SyncCursor
    {
        return new SyncCursor(
            runId: $this->runId,
            phase: $this->phase,
            providerCursor: $this->providerCursor,
            updatedSince: $this->updatedSince,
            highWatermark: $this->highWatermark,
            pagesDone: $this->pagesDone,
            mode: $this->mode,
            pendingDependencies: $this->pending,
        );
    }

    public function report(SyncStatus $status, ?DateTimeImmutable $syncedAt = null): SyncReport
    {
        return new SyncReport(
            runId: $this->runId,
            boardId: $this->board->getId(),
            provider: $this->provider->id(),
            mode: $this->mode,
            status: $status,
            dryRun: $this->options->dryRun,
            created: $this->created,
            updated: $this->updated,
            unchanged: $this->unchanged,
            archived: $this->archived,
            restored: $this->restored,
            skips: new SkipCounts($this->skipReadOnly, $this->skipElsewhere, $this->skipLocallyDeleted, $this->skipLocallyArchived, $this->skipRepeated),
            conflicts: $this->conflicts,
            columnsCreated: $this->columnsCreated,
            dependenciesCreated: $this->dependenciesCreated,
            commentsCreated: $this->commentsCreated,
            commentsUpdated: $this->commentsUpdated,
            files: new FileCounts($this->filesCopied, $this->filesReused, $this->filesRefused, $this->bytes),
            warnings: $this->warnings,
            errors: $this->errors,
            cursor: $status->isResumable() ? $this->cursor() : null,
            refusalCode: $this->refusalCode,
            retryAfterSeconds: $this->retryAfter,
            pagesDone: $this->pagesDone,
            total: $this->total,
            startedAt: $this->startedAt,
            syncedAt: $syncedAt,
        );
    }
}
