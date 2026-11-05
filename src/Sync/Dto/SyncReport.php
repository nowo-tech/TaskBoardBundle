<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use DateTimeImmutable;
use DateTimeInterface;

use function array_map;
use function array_values;
use function is_array;

/**
 * Since 1.23: the outcome of a run (or of its slices so far). JSON-safe and secret-free ({@see self::toArray()} is
 * what the run history stores).
 */
final readonly class SyncReport
{
    /**
     * @param list<SyncConflict> $conflicts
     * @param list<SyncError> $warnings
     * @param list<SyncError> $errors
     */
    public function __construct(
        public string $runId,
        public string $boardId,
        public string $provider,
        public SyncMode $mode,
        public SyncStatus $status,
        public bool $dryRun = false,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $archived = 0,
        public int $restored = 0,
        public SkipCounts $skips = new SkipCounts(),
        public array $conflicts = [],
        public int $columnsCreated = 0,
        public int $dependenciesCreated = 0,
        public int $commentsCreated = 0,
        public int $commentsUpdated = 0,
        public FileCounts $files = new FileCounts(),
        public array $warnings = [],
        public array $errors = [],
        public ?SyncCursor $cursor = null,
        public ?string $refusalCode = null,
        public ?int $retryAfterSeconds = null,
        public int $pagesDone = 0,
        public ?int $total = null,
        public DateTimeImmutable $startedAt = new DateTimeImmutable(),
        public ?DateTimeImmutable $syncedAt = null,
        /**
         * Since 1.25: generic counters of the run that have no typed property (e.g. "checklist.created",
         * "detail.calls", "skipStatus"), all ints. The people and time counters are {@see self::$people} /
         * {@see self::$time}, never repeated here.
         *
         * @var array<string, int>
         */
        public array $stats = [],
        /**
         * Since 1.25: tasks of the board per column id after the run ("" = no column).
         *
         * @var array<string, int>
         */
        public array $perColumn = [],
        /** Since 1.25: the people directory counters. */
        public PeopleCounts $people = new PeopleCounts(),
        /** Since 1.25: the time phase (mode and counters). */
        public TimeCounts $time = new TimeCounts(),
    ) {
    }

    /** Since 1.25: tasks the run wrote or found unchanged (created + updated + unchanged; subtasks and closed included). */
    public function written(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }

    /** Since 1.25: one counter of {@see self::$stats} (0 when absent). */
    public function stat(string $name): int
    {
        return $this->stats[$name] ?? 0;
    }

    public function skipped(): int
    {
        return $this->skips->total();
    }

    public function isComplete(): bool
    {
        return $this->status === SyncStatus::Succeeded;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** Whether the mass-archive guard held tasks back (the UI offers to archive them anyway). */
    public function massArchiveHeld(): bool
    {
        foreach ($this->warnings as $warning) {
            if ($warning->code === 'mass_archive_guard') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runId'               => $this->runId,
            'boardId'             => $this->boardId,
            'provider'            => $this->provider,
            'mode'                => $this->mode->value,
            'status'              => $this->status->value,
            'dryRun'              => $this->dryRun,
            'created'             => $this->created,
            'updated'             => $this->updated,
            'unchanged'           => $this->unchanged,
            'archived'            => $this->archived,
            'restored'            => $this->restored,
            'skipped'             => $this->skipped(),
            'skips'               => $this->skips->toArray(),
            'conflicts'           => array_map(static fn (SyncConflict $c): array => $c->toArray(), $this->conflicts),
            'columnsCreated'      => $this->columnsCreated,
            'dependenciesCreated' => $this->dependenciesCreated,
            'commentsCreated'     => $this->commentsCreated,
            'commentsUpdated'     => $this->commentsUpdated,
            'files'               => $this->files->toArray(),
            'warnings'            => array_map(static fn (SyncError $e): array => $e->toArray(), $this->warnings),
            'errors'              => array_map(static fn (SyncError $e): array => $e->toArray(), $this->errors),
            'cursor'              => $this->cursor?->toArray(),
            'refusalCode'         => $this->refusalCode,
            'retryAfterSeconds'   => $this->retryAfterSeconds,
            'pagesDone'           => $this->pagesDone,
            'total'               => $this->total,
            'startedAt'           => $this->startedAt->format(DateTimeInterface::ATOM),
            'syncedAt'            => $this->syncedAt?->format(DateTimeInterface::ATOM),
            'written'             => $this->written(),
            'stats'               => $this->stats,
            'perColumn'           => $this->perColumn,
            'people'              => $this->people->toArray(),
            'time'                => $this->time->toArray(),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $list = static fn (string $key): array => is_array($data[$key] ?? null) ? $data[$key] : [];

        return new self(
            runId: SyncValues::string($data['runId'] ?? ''),
            boardId: SyncValues::string($data['boardId'] ?? ''),
            provider: SyncValues::string($data['provider'] ?? ''),
            mode: SyncMode::tryFrom(SyncValues::string($data['mode'] ?? '')) ?? SyncMode::Initial,
            status: SyncStatus::tryFrom(SyncValues::string($data['status'] ?? '')) ?? SyncStatus::Failed,
            dryRun: ($data['dryRun'] ?? false) === true,
            created: SyncValues::int($data['created'] ?? 0),
            updated: SyncValues::int($data['updated'] ?? 0),
            unchanged: SyncValues::int($data['unchanged'] ?? 0),
            archived: SyncValues::int($data['archived'] ?? 0),
            restored: SyncValues::int($data['restored'] ?? 0),
            skips: SkipCounts::fromArray($list('skips')),
            conflicts: array_values(array_map(static fn (mixed $c): SyncConflict => SyncConflict::fromArray(is_array($c) ? $c : []), $list('conflicts'))),
            columnsCreated: SyncValues::int($data['columnsCreated'] ?? 0),
            dependenciesCreated: SyncValues::int($data['dependenciesCreated'] ?? 0),
            commentsCreated: SyncValues::int($data['commentsCreated'] ?? 0),
            commentsUpdated: SyncValues::int($data['commentsUpdated'] ?? 0),
            files: FileCounts::fromArray($list('files')),
            warnings: array_values(array_map(static fn (mixed $e): SyncError => SyncError::fromArray(is_array($e) ? $e : []), $list('warnings'))),
            errors: array_values(array_map(static fn (mixed $e): SyncError => SyncError::fromArray(is_array($e) ? $e : []), $list('errors'))),
            cursor: is_array($data['cursor'] ?? null) ? SyncCursor::fromArray($data['cursor']) : null,
            refusalCode: isset($data['refusalCode']) ? SyncValues::string($data['refusalCode']) : null,
            retryAfterSeconds: isset($data['retryAfterSeconds']) ? SyncValues::int($data['retryAfterSeconds']) : null,
            pagesDone: SyncValues::int($data['pagesDone'] ?? 0),
            total: isset($data['total']) ? SyncValues::int($data['total']) : null,
            startedAt: SyncValues::date($data['startedAt'] ?? null) ?? new DateTimeImmutable(),
            syncedAt: SyncValues::date($data['syncedAt'] ?? null),
            stats: self::intMap($list('stats')),
            perColumn: self::intMap($list('perColumn')),
            people: PeopleCounts::fromArray($list('people')),
            time: TimeCounts::fromArray($list('time')),
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, int>
     */
    private static function intMap(array $values): array
    {
        $map = [];
        foreach ($values as $key => $value) {
            $map[(string) $key] = SyncValues::int($value);
        }

        return $map;
    }
}
