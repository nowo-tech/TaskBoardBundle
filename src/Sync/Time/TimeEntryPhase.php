<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Time;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Hook\ImportedTimeEntrySinkInterface;
use Nowo\TaskBoardBundle\Sync\People\PeopleSession;
use Nowo\TaskBoardBundle\Sync\People\PersonFilter;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPersonRepository;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteTimeEntry;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TimeEntryQuery;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;
use Psr\Clock\ClockInterface;
use SortDirection;
use Symfony\Component\Clock\NativeClock;

use function array_chunk;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function intdiv;
use function is_string;
use function max;
use function min;

/**
 * Since 1.25: the `time_entries` phase — read-only tracked time from the provider, window by window (30 days by
 * default), handed to the host's sink. Admin token: every directory person's entries (`assignee` chunks); member
 * token: the token user's own entries, with the warning `time_member_mode`; per-task fallback only when the host asks
 * (`SyncOptions::timeFallbackPerTask`). A window over `max_entries_per_window` rows is halved and read again (down to
 * one hour). Resumable per window and chunk.
 */
final readonly class TimeEntryPhase
{
    public const MODE_FALLBACK = 'fallback';

    private const MIN_WINDOW_SECONDS = 3600;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SyncStore $store,
        private TaskSyncPeopleDirectoryService $directories,
        private TaskSyncPersonRepository $people,
        private ?ImportedTimeEntrySinkInterface $sink = null,
        private ClockInterface $clock = new NativeClock(),
        private int $windowDays = 30,
        private int $maxAssigneesPerCall = 25,
        private int $maxEntriesPerWindow = 10_000,
        private int $maxWindowsPerRun = 200,
        private int $fallbackMaxTasks = 500,
        // Since 1.25 (audit FP-L4): provider calls one slice of the time phase may spend (splits included).
        private int $maxCallsPerRun = 500,
    ) {
    }

    /** Whether the phase can run for the context (a sink, a directory and a provider that serves time). */
    public function supports(SyncRunContext $ctx): bool
    {
        return $this->sink instanceof ImportedTimeEntrySinkInterface
            && $ctx->options->timeEntries
            && $ctx->directoryId !== null
            && $ctx->provider instanceof TimeEntryProviderInterface
            && $ctx->provider instanceof PeopleProviderInterface
            && $ctx->provider->capabilities()->timeEntries;
    }

    /**
     * Runs windows until the end, the window cap of a run, a cancel request or the deadline; true when finished.
     */
    public function run(SyncRunContext $ctx, ?int $deadline): bool
    {
        $provider  = $ctx->provider;
        $directory = $ctx->directoryId === null ? null : $this->entityManager->find(TaskSyncDirectory::class, $ctx->directoryId);
        if (!$this->sink instanceof ImportedTimeEntrySinkInterface || !$directory instanceof TaskSyncDirectory || !$provider instanceof TimeEntryProviderInterface || !$provider instanceof PeopleProviderInterface) {
            return true;
        }
        if ($ctx->timeMode === null) {
            $access        = $provider->timeAccess($ctx->credential, $directory->getWorkspaceId());
            $ctx->timeMode = $access->mode === TimeAccessMode::Member && $ctx->options->timeFallbackPerTask ? self::MODE_FALLBACK : $access->mode->value;
            if ($access->mode === TimeAccessMode::Member) {
                $ctx->warn('time_member_mode');
            }
        }
        if ($ctx->timeMode === self::MODE_FALLBACK) {
            $this->fallback($ctx, $directory, $provider);

            return true;
        }
        $access   = new TimeAccess(TimeAccessMode::tryFrom((string) $ctx->timeMode) ?? TimeAccessMode::Member);
        $location = $ctx->connection->getLocation();
        $now      = $this->clock->now();
        $start    = $ctx->timeWindowStart ?? $ctx->options->timeFrom ?? $this->oldest($ctx);
        $chunks   = $access->mode === TimeAccessMode::Admin ? $this->assigneeChunks($directory) : [null];
        $calls    = 0;
        for ($windows = 0; $start < $now && $windows < $this->maxWindowsPerRun; ++$windows) {
            $end    = min($start->add(new DateInterval('P' . $this->windowDays . 'D')), $now); // @igor-ignore - DateTimeImmutable
            $window = new TimeWindow($start, $end, $location);
            for ($chunk = $ctx->timeChunk; $chunk < count($chunks); ++$chunk) {
                $query = new TimeEntryQuery($directory->getWorkspaceId(), $location, $start, $end, $chunks[$chunk]);
                if (!$this->readWindow($provider, $ctx, $directory, $window, $access, $query, $chunk === count($chunks) - 1, $calls, $deadline)) {
                    return false; // the call budget or the deadline: the cursor holds the split position
                }
                $ctx->timeChunk = $chunk + 1;
            }
            $ctx->timeChunk       = 0;
            $start                = $end;
            $ctx->timeWindowStart = $start;
            $ctx->count('time.windows');
            if ($this->stop($ctx, $deadline)) {
                return $start >= $now;
            }
        }

        return $start >= $now;
    }

    /**
     * One window and chunk, split by split (audit FP-L4): a split at the row cap is halved and read again (down to one
     * hour); every complete split is delivered at once (memory stays at one split), and the position is kept in the
     * cursor, so a rate limit, the call budget or the deadline resume from the last delivered split. False = paused.
     */
    private function readWindow(TimeEntryProviderInterface $provider, SyncRunContext $ctx, TaskSyncDirectory $directory, TimeWindow $window, TimeAccess $access, TimeEntryQuery $query, bool $lastChunk, int &$calls, ?int $deadline): bool
    {
        $end      = $query->to->getTimestamp();
        $position = $ctx->timeSplitStart ?? $query->from;
        $span     = $ctx->timeSplitSeconds > 0 ? $ctx->timeSplitSeconds : $end - $query->from->getTimestamp();
        while ($position->getTimestamp() < $end) {
            // The call budget always; the deadline (and a cancel) inside a split window, after at least one call.
            if ($calls > 0 && ($calls >= $this->maxCallsPerRun || ($ctx->timeSplitSeconds > 0 && $this->stop($ctx, $deadline)))) {
                $ctx->warn('time_paused');

                return false;
            }
            $to = $position->setTimestamp(min($position->getTimestamp() + $span, $end)); // @igor-ignore - DateTimeImmutable
            ++$calls;
            $entries = [];
            foreach ($provider->fetchTimeEntries($ctx->credential, new TimeEntryQuery($query->workspaceId, $query->location, $position, $to, $query->assigneeIds)) as $entry) {
                $entries[] = $entry;
            }
            $seconds = $to->getTimestamp() - $position->getTimestamp();
            if (count($entries) >= $this->maxEntriesPerWindow) {
                if ($seconds <= self::MIN_WINDOW_SECONDS) {
                    throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
                }
                $span                  = intdiv($seconds, 2);
                $ctx->timeSplitStart   = $position;
                $ctx->timeSplitSeconds = $span;
                $ctx->count('time.windowsSplit');
                continue;
            }
            $this->deliver($ctx, $directory, $window, $access, $entries, $lastChunk && $to->getTimestamp() >= $end);
            $position              = $to;
            $ctx->timeSplitStart   = $position->getTimestamp() < $end ? $position : null;
            $ctx->timeSplitSeconds = $position->getTimestamp() < $end ? $span : 0;
        }

        return true;
    }

    private function stop(SyncRunContext $ctx, ?int $deadline): bool
    {
        return $this->store->isCancelRequested($ctx->runId) || ($deadline !== null && $this->clock->now()->getTimestamp() >= $deadline);
    }

    /**
     * @param list<RemoteTimeEntry> $entries
     */
    private function deliver(SyncRunContext $ctx, TaskSyncDirectory $directory, TimeWindow $window, TimeAccess $access, array $entries, bool $complete): void
    {
        $session    = $this->directories->session($directory, $ctx->board);
        $candidates = [];
        $taskIds    = [];
        foreach ($entries as $entry) {
            if ($entry->running || $entry->durationSeconds <= 0) {
                $ctx->count('time.skippedRunning');
                continue;
            }
            if ($entry->taskId === null) {
                $ctx->count('time.skippedTaskless');
                continue;
            }
            $taskIds[$entry->taskId] = true;
            $candidates[]            = $entry;
        }
        $items = $taskIds === [] ? [] : $this->store->itemsByExternalIds($ctx->connection, array_map(static fn (int|string $id): string => Text::cut((string) $id, 128), array_keys($taskIds)));
        $list  = [];
        foreach ($candidates as $entry) {
            $person = $session->person($entry->person, TaskSyncPerson::KIND_TIME_ENTRY);
            // Audit FP-I2: only people mapped to a user (auto / manual) are handed to the host; the others are counted.
            if (!$person->getMapping()->isMapped() || $person->getMappedUser() === null) {
                $ctx->count('time.skippedUnmapped');
                continue;
            }
            $task   = $items[(string) $entry->taskId] ?? null;
            $list[] = new ImportedTimeEntryCandidate($entry, $person, $task instanceof TaskSyncItem ? $task->getTask() : null);
        }
        $this->entityManager->flush();
        $this->close($ctx, $session);
        $this->sink?->accept($ctx->board, $directory, $window, $access, $list, $complete);
        $ctx->count('time.entries', count($list));
    }

    /** Member token, on request: the legacy per-task intervals of the board's linked tasks (bounded). */
    private function fallback(SyncRunContext $ctx, TaskSyncDirectory $directory, TimeEntryProviderInterface $provider): void
    {
        $now    = $this->clock->now();
        $window = new TimeWindow($now->setTimestamp(0), $now, $ctx->connection->getLocation()); // @igor-ignore - DateTimeImmutable
        /** @var list<TaskSyncItem> $items */
        $items = $this->entityManager->createQueryBuilder()->select('i')->from(TaskSyncItem::class, 'i')
            ->where('i.connection = :c')->andWhere('i.task IS NOT NULL')->andWhere('i.itemKind = :kind')
            ->setParameter('c', $ctx->connection)->setParameter('kind', TaskSyncItem::KIND_TASK)
            ->orderBy('i.id', SortDirection::Ascending)->setMaxResults($this->fallbackMaxTasks + 1)->getQuery()->getResult();
        if (count($items) > $this->fallbackMaxTasks) {
            $ctx->warn('time_fallback_partial');
        }
        foreach (array_values($items) as $index => $item) {
            if ($index >= $this->fallbackMaxTasks) {
                break;
            }
            $intervals = $provider->fetchTaskTimeIntervals($ctx->credential, $item->getExternalId());
            if ($intervals === null) {
                $ctx->warn('time_fallback_unavailable');

                return;
            }
            $ctx->count('time.fallbackTasks');
            $entries = [];
            foreach ($intervals as $entry) {
                $entries[] = $entry;
            }
            $this->deliver($ctx, $directory, $window, new TimeAccess(TimeAccessMode::Member), $entries, true);
        }
    }

    /**
     * The directory's people as `assignee` chunks (provider ids only; never user input).
     *
     * @return list<list<string>|null>
     */
    private function assigneeChunks(TaskSyncDirectory $directory): array
    {
        $ids = [];
        foreach ($this->people->forDirectory($directory, new PersonFilter(), 100_000) as $person) {
            $ids[] = $person->getProviderUserId();
        }

        return $ids === [] ? [null] : array_chunk($ids, max(1, $this->maxAssigneesPerCall));
    }

    /** The first window's start: the oldest task of the board, else 30 days back. */
    private function oldest(SyncRunContext $ctx): DateTimeImmutable
    {
        $oldest = $this->entityManager->createQueryBuilder()->select('MIN(t.createdAt)')->from(Task::class, 't')
            ->where('t.board = :board')->setParameter('board', $ctx->board)->getQuery()->getSingleScalarResult();
        $now = $this->clock->now();

        return is_string($oldest) && $oldest !== '' ? new DateTimeImmutable($oldest) : $now->sub(new DateInterval('P30D'));
    }

    private function close(SyncRunContext $ctx, PeopleSession $session): void
    {
        foreach ($session->stats as $name => $value) {
            $ctx->count($name, $value);
        }
        foreach ($session->warnings as $code) {
            $ctx->warn($code);
        }
    }
}
