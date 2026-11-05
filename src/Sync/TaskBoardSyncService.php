<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Dto\SyncCursor;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncMode;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncPhase;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Event\BoardConnectionEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncFailedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncFinishedEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncPageDoneEvent;
use Nowo\TaskBoardBundle\Sync\Event\SyncStartedEvent;
use Nowo\TaskBoardBundle\Sync\Event\TaskRemoteArchivedEvent;
use Nowo\TaskBoardBundle\Sync\Event\TaskRemoteRestoredEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Nowo\TaskBoardBundle\Sync\Time\TimeEntryPhase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function intdiv;

/**
 * Since 1.23: links a board to a remote list and keeps it in sync — an idempotent upsert keyed by the same
 * (import scope, external id) the file imports use, resumable from a cursor, one run per board.
 *
 * Every run clears the entity manager after each page (memory stays flat): entities loaded before the call are
 * detached afterwards. Nothing here stores, logs or dispatches the credential.
 */
final readonly class TaskBoardSyncService
{
    /** A run whose heartbeat is older than this is considered dead and its lock may be taken over. */
    private const STALE_AFTER = 'PT10M';

    /** The incremental window starts this long before the watermark (clock skew; the upsert is idempotent). */
    private const OVERLAP_SECONDS = 120;

    /** The page size the safety cap counts with (contract §4). */
    private const PAGE_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SyncStore $store,
        private SyncTaskWriter $writer,
        private TaskProviderRegistry $providers,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?ProviderCredentialResolverInterface $credentials = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
        private ClockInterface $clock = new NativeClock(),
        private int $maxTasksPerRun = 5000,
        // Since audit EG-L2: the most task pages one run reads over all its slices (`sync.max_pages_per_run`).
        private int $maxPagesPerRun = 1000,
        private float $maxArchiveRatio = 0.5,
        private int $historyKeep = 50,
        private int $maxBytesPerRun = 1_073_741_824,
        // Since 1.25: people directory, time phase, sources.
        private ?TaskSyncPeopleDirectoryService $people = null,
        private ?TimeEntryPhase $timePhase = null,
        private ?BoardColumnManager $columnManager = null,
    ) {
    }

    /**
     * Links the board to a remote list (the first step of the connection page); the first run imports it.
     *
     * @param array<string, bool|string|null> $options createMissingColumns, fallbackColumnId, importComments,
     *                                                 importAttachments, archiveRemoteDeletions
     * @param iterable<mixed> $statusColumnMap since 1.25: the dialog's `list<{status, column}>`
     */
    public function link(TaskBoard $board, TaskProviderInterface $provider, ProviderCredential $credential, RemoteContainer $container, object $actor, array $options = [], iterable $statusColumnMap = []): TaskBoardConnection
    {
        if ($this->store->findConnection($board) instanceof TaskBoardConnection) {
            throw new LogicException('The board is already linked.');
        }
        $connection = new TaskBoardConnection(
            board: $board,
            provider: $provider->id(),
            importScope: Text::cut($provider->importScope($credential), 64),
            connectionRef: Text::cut($credential->connectionRef, 191),
            containerId: Text::cut($container->id, 128),
            containerLabel: $container->name,
            createdBy: $this->managedUser($actor),
            containerPath: $container->path,
            options: $options,
        );
        // Since 1.25: a folder / space source, and the dialog's status → column map ("__create" columns are created
        // now, when the link is confirmed, in the statuses' order).
        $location = RemoteLocation::fromContainerId($container->id);
        if ($location instanceof RemoteLocation) {
            $connection->setLocation($location);
        }
        $map = StatusColumns::clean($statusColumnMap, $board);
        foreach ($map as $entry) {
            if ($entry['column'] === StatusColumns::CREATE && $this->columnManager instanceof BoardColumnManager && !$this->hasColumnNamed($board, $entry['status'])) {
                $this->columnManager->add($board, new BoardColumnFormData(name: Text::cut($entry['status'], 255))); // @igor-ignore - writes the board, the manager keeps no state
            }
        }
        $connection->setStatusColumnMap($map);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();
        $this->eventDispatcher?->dispatch(new BoardConnectionEvent($board, $provider->id(), $connection->getContainerId(), $connection->getContainerLabel(), $connection->getConnectionRef(), $actor), TaskBoardEvents::BOARD_CONNECTION_LINKED);
        $this->logger->info('TaskBoard sync linked', ['bundle' => 'nowo_task_board', 'board_id' => $board->getId(), 'provider' => $provider->id()]);

        return $connection;
    }

    /**
     * Since 1.25: replaces the status → column map of a linked board (the connection page); "__create" columns are
     * created now.
     *
     * @param iterable<mixed> $statusColumnMap
     */
    public function updateStatusColumnMap(TaskBoard $board, iterable $statusColumnMap): ?TaskBoardConnection
    {
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection) {
            return null;
        }
        $map = StatusColumns::clean($statusColumnMap, $board);
        foreach ($map as $entry) {
            if ($entry['column'] === StatusColumns::CREATE && $this->columnManager instanceof BoardColumnManager && !$this->hasColumnNamed($board, $entry['status'])) {
                $this->columnManager->add($board, new BoardColumnFormData(name: Text::cut($entry['status'], 255))); // @igor-ignore - writes the board, the manager keeps no state
            }
        }
        $connection->setStatusColumnMap($map);
        $this->entityManager->flush();

        return $connection;
    }

    /** Removes the link and its sync state; the tasks, their import links and the stored files stay. */
    public function unlink(TaskBoard $board, object $actor): void
    {
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection) {
            return;
        }
        $event     = new BoardConnectionEvent($board, $connection->getProvider(), $connection->getContainerId(), $connection->getContainerLabel(), $connection->getConnectionRef(), $actor);
        $directory = $connection->getDirectory();
        $this->store->deleteConnection($connection);
        // Since 1.25: the people directory goes (anonymised where still referenced) with its last connection.
        if ($directory instanceof TaskSyncDirectory && $this->people instanceof TaskSyncPeopleDirectoryService) {
            $this->people->pruneDirectory($directory);
        }
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_CONNECTION_UNLINKED);
        $this->logger->info('TaskBoard sync unlinked', ['bundle' => 'nowo_task_board', 'board_id' => $board->getId(), 'provider' => $event->provider]);
    }

    /** Asks a running run of the board to stop after its current page. */
    public function requestCancel(TaskBoard $board, string $runId): bool
    {
        $run = $this->store->findRun($runId);
        if (!$run instanceof TaskSyncRun || $run->getBoardId() !== $board->getId() || $run->getStatus()->isTerminal()) {
            return false;
        }
        $run->requestCancel();
        $connection = $run->getConnection();
        if ($connection->getRunningRunId() === null) {
            // A paused run (nothing running): it ends now.
            $connection->setCursor(null);
            $run->record(SyncReport::fromArray(['status' => SyncStatus::Cancelled->value] + ($run->getReport()?->toArray() ?? [])), $this->clock->now());
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * The linked board's run: its provider, list, stored options and cursor, with the credential the host gives.
     */
    public function runLinked(TaskBoard $board, SyncOptions $options): SyncReport
    {
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection) {
            return $this->bare($board, '', SyncStatus::Failed, 'not_linked');
        }
        if (!$this->providers->has($connection->getProvider())) {
            return $this->bare($board, $connection->getProvider(), SyncStatus::Failed, 'provider_unavailable');
        }
        $credential = $this->credentials?->credentialFor($board, $connection->getConnectionRef());
        if (!$credential instanceof ProviderCredential) {
            return $this->bare($board, $connection->getProvider(), SyncStatus::Failed, 'credential_unavailable');
        }

        return $this->run($board, $this->providers->get($connection->getProvider()), $credential, $connection->getContainerId(), new SyncOptions(
            actor: $options->actor,
            trigger: $options->trigger,
            full: $options->full,
            dryRun: $options->dryRun,
            createMissingColumns: $connection->option('createMissingColumns', $options->createMissingColumns),
            fallbackColumnId: $connection->fallbackColumnId() ?? $options->fallbackColumnId,
            importComments: $connection->option('importComments', $options->importComments),
            importAttachments: $connection->option('importAttachments', $options->importAttachments),
            archiveRemoteDeletions: $connection->option('archiveRemoteDeletions', $options->archiveRemoteDeletions),
            allowMassArchive: $options->allowMassArchive,
            maxPages: $options->maxPages,
            timeBudgetSeconds: $options->timeBudgetSeconds,
            people: $options->people,
            requirePeopleMapping: $options->requirePeopleMapping,
            timeEntries: $options->timeEntries,
            timeFrom: $options->timeFrom,
            timeFallbackPerTask: $options->timeFallbackPerTask,
            watchers: $options->watchers,
            checklists: $options->checklists,
        ));
    }

    /**
     * One run (or one slice of it). The first use links the board to `$listId`; a board linked to another list or
     * provider is refused. A concurrent run of the board gets status Busy and writes nothing.
     */
    public function run(TaskBoard $board, TaskProviderInterface $provider, ProviderCredential $credential, string $listId, SyncOptions $options, ?SyncCursor $resume = null): SyncReport
    {
        // A caller may pass an actor detached by an earlier clear (e.g. an uninitialised proxy): the access check and
        // its BOARD_ACCESS_CHECK listeners get the managed instance, which they may read safely.
        $actor = $this->attached($options->actor);
        if (!$this->accessChecker->canManageBoardConnection($actor, $board)) {
            return $this->bare($board, $provider->id(), SyncStatus::Failed, 'actor_not_allowed');
        }
        $connection = $this->store->findConnection($board);
        if (!$connection instanceof TaskBoardConnection) {
            $container  = $provider->findContainer($credential, $listId) ?? new RemoteContainer($listId, $listId, ContainerType::List, null, true, false);
            $connection = $this->link($board, $provider, $credential, $container, $actor);
        } elseif ($connection->getProvider() !== $provider->id() || $connection->getContainerId() !== $listId) {
            return $this->bare($board, $provider->id(), SyncStatus::Failed, 'board_linked_elsewhere');
        }

        $now    = $this->clock->now();
        $resume = $options->dryRun ? null : ($resume ?? $connection->getCursor());
        $runId  = $resume instanceof SyncCursor && $resume->runId !== '' ? $resume->runId : TaskSyncRun::newId();
        if (!$this->store->acquireLock($connection, $runId, $now, $now->sub(new DateInterval(self::STALE_AFTER)))) {
            return $this->bare($board, $provider->id(), SyncStatus::Busy, null, $runId);
        }

        $connectionId = $connection->getId();
        try {
            $ctx = $this->context($board, $connection, $provider, $credential, $options, $resume, $runId, $now);
            $this->attach($ctx);
            $run = $this->store->findRun($runId);
            if ($run instanceof TaskSyncRun && $run->getReport() instanceof SyncReport) {
                $ctx->resumeFrom($run->getReport());
            } else {
                $run = new TaskSyncRun($runId, $connection, $board->getId(), $this->managedUser($options->actor), $options->trigger->value, $ctx->mode->value, $options->dryRun, $now);
                $this->entityManager->persist($run);
                if ($ctx->mode === SyncMode::Initial || $ctx->mode === SyncMode::Full) {
                    $ctx->total = $provider->findContainer($credential, $listId)?->taskCount;
                }
                // Audit EG-L2: a run reads at most max_pages_per_run task pages. Audit EG-L7: never fewer from the list's
                // task count, which may leave out the subtasks and closed tasks the pages hold.
                $ctx->pageCap = $this->maxPagesPerRun;
            }
            $connection->markRun($now);
            $run->record($ctx->report(SyncStatus::Running), $now);
            $this->entityManager->flush();
            $this->eventDispatcher?->dispatch(new SyncStartedEvent($board, $runId, $provider->id(), $ctx->mode, $options->trigger, $ctx->actor, $options->dryRun), TaskBoardEvents::SYNC_STARTED);
            $this->logger->info('TaskBoard sync started', $this->logContext($ctx));

            $status = $this->execute($ctx);

            return $this->finish($ctx, $status);
        } catch (Throwable $exception) {
            // Anything before the phases (the provider's container lookup, the database): the run fails cleanly.
            $this->logger->error('TaskBoard sync failed', ['bundle' => 'nowo_task_board', 'board_id' => $board->getId(), 'run_id' => $runId, 'exception' => $exception::class]);
            $report = $this->bare($board, $provider->id(), SyncStatus::Failed, $exception instanceof ProviderException ? $exception->errorCode->value : 'internal', $runId);
            try {
                $this->store->findRun($runId)?->record($report, $this->clock->now());
                $this->entityManager->flush();
            } catch (Throwable) {
                // The history row stays as it was; the lock is still released.
            }
            $this->eventDispatcher?->dispatch(new SyncFailedEvent($board, $report->errors[0], $report), TaskBoardEvents::SYNC_FAILED);

            return $report;
        } finally {
            $this->store->releaseLock($connectionId, $runId);
        }
    }

    private function context(TaskBoard $board, TaskBoardConnection $connection, TaskProviderInterface $provider, ProviderCredential $credential, SyncOptions $options, ?SyncCursor $resume, string $runId, DateTimeImmutable $now): SyncRunContext
    {
        $watermark = $connection->getWatermark();
        $mode      = $resume->mode ?? ($options->full ? SyncMode::Full : ($watermark instanceof DateTimeImmutable ? SyncMode::Incremental : SyncMode::Initial));
        $since     = $resume instanceof SyncCursor ? $resume->updatedSince : ($mode === SyncMode::Incremental && $watermark instanceof DateTimeImmutable ? $watermark->sub(new DateInterval('PT' . self::OVERLAP_SECONDS . 'S')) : null);
        $ctx       = new SyncRunContext($runId, $board, $connection, $provider, $credential, $options, $options->actor, $mode, $since, $now, $connection->getImportScope(), $this->maxBytesPerRun);
        if ($resume instanceof SyncCursor) {
            $ctx->phase           = $resume->phase;
            $ctx->providerCursor  = $resume->providerCursor;
            $ctx->highWatermark   = $resume->highWatermark;
            $ctx->pagesDone       = $resume->pagesDone;
            $ctx->tasksSeen       = $resume->tasksSeen;
            $ctx->pageCap         = $resume->pageCap;
            $ctx->pending         = $resume->pendingDependencies;
            $ctx->timeWindowStart = $resume->timeWindowStart;
            $ctx->timeChunk       = $resume->timeChunk;
            $ctx->timeMode        = $resume->timeMode;
        }
        // Since 1.25: people (and so tracked time) go through the directory only when the run asks for them.
        $ctx->directoryId = $options->people ? $connection->getDirectory()?->getId() : null;

        return $ctx;
    }

    /**
     * The phases; returns how the slice ended. Provider errors, limits and unexpected errors become statuses.
     */
    private function execute(SyncRunContext $ctx): SyncStatus
    {
        try {
            if ($ctx->phase === SyncPhase::Tasks && $ctx->pagesDone === 0) {
                $this->refreshPeople($ctx);
            }
            $source = $this->source($ctx);
            if ($ctx->phase === SyncPhase::Tasks) {
                $slicePages = 0;
                $sliceSeen  = $ctx->tasksSeen;
                $deadline   = $ctx->options->timeBudgetSeconds !== null ? $this->clock->now()->getTimestamp() + $ctx->options->timeBudgetSeconds : null;
                // Audit EG-L2 (contract §4): a slice reads at most ceil(max_tasks_per_run / 100) + 1 pages, and the
                // run (all its slices: the page count is kept in the cursor) at most its page cap.
                $pageCap = intdiv($this->maxTasksPerRun + self::PAGE_SIZE - 1, self::PAGE_SIZE) + 1;
                $runCap  = $ctx->pageCap > 0 ? $ctx->pageCap : $this->maxPagesPerRun;
                while (true) {
                    if ($this->store->isCancelRequested($ctx->runId)) {
                        return SyncStatus::Cancelled;
                    }
                    if ($slicePages >= $pageCap || $ctx->pagesDone >= $runCap) {
                        throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
                    }
                    $page = $source instanceof RemoteSource && $ctx->provider instanceof SourceProviderInterface
                        ? $ctx->provider->fetchSourceTasks($ctx->credential, $source, $ctx->updatedSince, $ctx->providerCursor)
                        : $ctx->provider->fetchTasks($ctx->credential, $ctx->connection->getContainerId(), $ctx->updatedSince, $ctx->providerCursor);
                    $seenWas = $ctx->tasksSeen;
                    $this->writer->writePage($ctx, $page->tasks);
                    $this->dispatchTaskEvents($ctx);
                    $this->reload($ctx);
                    ++$ctx->pagesDone;
                    ++$slicePages;
                    $ctx->providerCursor = $page->nextCursor;
                    if ($page->lastPage || $page->nextCursor === null) {
                        break;
                    }
                    if ($ctx->tasksSeen === $seenWas) {
                        // A page that brings no new task (empty, or only ids already read): the list does not advance.
                        $ctx->warn('paging_stopped');
                        $ctx->pagingStopped = true;
                        break;
                    }
                    $this->progress($ctx);
                    if (($ctx->options->maxPages !== null && $slicePages >= $ctx->options->maxPages)
                        || ($deadline !== null && $this->clock->now()->getTimestamp() >= $deadline)
                        || $ctx->tasksSeen - $sliceSeen >= $this->maxTasksPerRun) {
                        return SyncStatus::Paused;
                    }
                }
                $this->writer->resolvePending($ctx);
                $ctx->phase          = SyncPhase::Deletions;
                $ctx->providerCursor = null;
            }
            if ($ctx->phase === SyncPhase::Deletions && $ctx->options->archiveRemoteDeletions) {
                try {
                    $ids      = [];
                    $existing = $source instanceof RemoteSource && $ctx->provider instanceof SourceProviderInterface
                        ? $ctx->provider->existingSourceTaskIds($ctx->credential, $source)
                        : $ctx->provider->existingTaskIds($ctx->credential, $ctx->connection->getContainerId());
                    foreach ($existing as $id) {
                        $ids[Text::cut($id, 128)] = true;
                    }
                    $this->writer->applyDeletions($ctx, $ids, $this->maxArchiveRatio);
                    $this->dispatchTaskEvents($ctx);
                } catch (RateLimitedException $exception) {
                    throw $exception;
                } catch (ProviderException) {
                    // A partial scan never archives anything.
                    $ctx->warn('deletions_skipped');
                }
            }
            if ($ctx->phase === SyncPhase::Deletions) {
                $ctx->phase = SyncPhase::TimeEntries;
            }
            if ($ctx->phase === SyncPhase::TimeEntries && !$ctx->options->dryRun && $this->timePhase instanceof TimeEntryPhase && $this->timePhase->supports($ctx)) {
                $deadline = $ctx->options->timeBudgetSeconds !== null ? $this->clock->now()->getTimestamp() + $ctx->options->timeBudgetSeconds : null;
                if (!$this->timePhase->run($ctx, $deadline)) {
                    return $this->store->isCancelRequested($ctx->runId) ? SyncStatus::Cancelled : SyncStatus::Paused;
                }
            }
            $ctx->phase = SyncPhase::Done;

            return SyncStatus::Succeeded;
        } catch (RateLimitedException $exception) {
            $ctx->retryAfter = $exception->retryAfterSeconds;

            return SyncStatus::RateLimited;
        } catch (SyncRefusedException $exception) {
            $ctx->refusalCode = $exception->refusal->code;

            return SyncStatus::Refused;
        } catch (ProviderException $exception) {
            $ctx->errors[] = new SyncError($exception->errorCode->value);

            return SyncStatus::Failed;
        } catch (Throwable $exception) {
            $this->logger->error('TaskBoard sync error', $this->logContext($ctx) + ['exception' => $exception::class]);
            $ctx->errors[] = new SyncError('internal');

            return SyncStatus::Failed;
        }
    }

    private function finish(SyncRunContext $ctx, SyncStatus $status): SyncReport
    {
        $now    = $this->clock->now();
        $report = $ctx->report($status, $status === SyncStatus::Succeeded ? $now : null);
        try {
            $this->reload($ctx);
            if (!$ctx->options->dryRun) {
                $ctx->connection->setCursor($status->isResumable() ? $ctx->cursor() : null);
                if ($status === SyncStatus::Succeeded) {
                    // Audit EG-I11: a list that stopped advancing keeps the previous watermark (the next run reads again
                    // what this one may have missed).
                    $ctx->connection->markSuccess($now, $ctx->pagingStopped ? null : $ctx->highWatermark);
                }
            }
            if ($status === SyncStatus::Succeeded) {
                $ctx->perColumn = $this->store->tasksPerColumn($ctx->board);
                $report         = $ctx->report($status, $now);
            }
            $run = $this->store->findRun($ctx->runId);
            $run?->record($report, $now);
            $run?->setTimeMode($ctx->timeMode);
            $this->entityManager->flush();
            $this->store->prune($ctx->connection, $this->historyKeep);
        } catch (Throwable $exception) {
            $this->logger->error('TaskBoard sync history not saved', $this->logContext($ctx) + ['exception' => $exception::class]);
        }

        if ($status === SyncStatus::Failed) {
            $this->logger->warning('TaskBoard sync failed', $this->logContext($ctx) + ['error_code' => $report->errors[0]->code ?? 'internal']);
            $this->eventDispatcher?->dispatch(new SyncFailedEvent($ctx->board, $report->errors[0] ?? new SyncError('internal'), $report), TaskBoardEvents::SYNC_FAILED);
        } else {
            $this->logger->info('TaskBoard sync finished', $this->logContext($ctx) + ['status' => $status->value]);
            $this->eventDispatcher?->dispatch(new SyncFinishedEvent($ctx->board, $report), TaskBoardEvents::SYNC_FINISHED);
        }

        return $report;
    }

    /**
     * Since 1.25: attaches the people directory and refreshes it from the workspace members; refuses a first import
     * whose People step was not saved when the host asks (`requirePeopleMapping`).
     */
    private function refreshPeople(SyncRunContext $ctx): void
    {
        if (!$ctx->options->people || !$this->people instanceof TaskSyncPeopleDirectoryService || !$ctx->provider instanceof PeopleProviderInterface) {
            return;
        }
        $directory = $this->people->directoryFor($ctx->connection, $ctx->provider, $ctx->credential);
        if (!$directory instanceof TaskSyncDirectory) {
            return;
        }
        $ctx->directoryId = $directory->getId();
        if (!$ctx->options->dryRun) {
            $session = $this->people->session($directory, $ctx->board);
            $this->people->refreshMembers($session, $ctx->provider, $ctx->credential);
            foreach ($session->stats as $name => $value) {
                $ctx->count($name, $value);
            }
            foreach ($session->warnings as $code) {
                $ctx->warn($code);
            }
            $this->logger->info('TaskBoard sync people phase done', ['bundle' => 'nowo_task_board', 'board_id' => $ctx->board->getId(), 'directory' => $directory->getId(), 'counts' => $session->stats]);
        }
        // A first import waits for the People step when the host asks and someone is still undecided.
        if ($ctx->options->requirePeopleMapping && !$directory->getReviewedAt() instanceof DateTimeImmutable && $this->store->linkedItems($ctx->connection) === [] && $this->people->unreviewedCount($directory) > 0) {
            throw new SyncRefusedException(new LimitRefusal('people_mapping_required'));
        }
    }

    /** Since 1.25: the folder / space source of the connection (null for a list: the 1.23 calls). */
    private function source(SyncRunContext $ctx): ?RemoteSource
    {
        $location = $ctx->connection->getLocation();
        if ($location->kind === RemoteLocationKind::List || !$ctx->provider instanceof SourceProviderInterface) {
            return null;
        }
        $source = $ctx->provider->findSource($ctx->credential, $location->containerId());
        if (!$source instanceof RemoteSource) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }

        return $source;
    }

    private function hasColumnNamed(TaskBoard $board, string $name): bool
    {
        foreach ($board->getColumns() as $column) {
            if (StatusColumns::normalize($column->getName()) === StatusColumns::normalize($name)) {
                return true;
            }
        }

        return false;
    }

    /** Progress of a running run: heartbeat, the history row, SYNC_PAGE_DONE. */
    private function progress(SyncRunContext $ctx): void
    {
        $now = $this->clock->now();
        $this->store->heartbeat($ctx->connection->getId(), $ctx->runId, $now);
        $report = $ctx->report(SyncStatus::Running);
        $this->store->findRun($ctx->runId)?->record($report, $now);
        $this->entityManager->flush();
        $this->eventDispatcher?->dispatch(new SyncPageDoneEvent($ctx->board, $report), TaskBoardEvents::SYNC_PAGE_DONE);
    }

    private function dispatchTaskEvents(SyncRunContext $ctx): void
    {
        $events          = $ctx->taskEvents;
        $ctx->taskEvents = [];
        if ($ctx->options->dryRun) {
            return;
        }
        foreach ($events as [$kind, $taskId, $externalId]) {
            $task = $this->entityManager->find(Task::class, $taskId);
            if (!$task instanceof Task) {
                continue;
            }
            if ($kind === 'archived') {
                $this->eventDispatcher?->dispatch(new TaskRemoteArchivedEvent($task, $ctx->provider->id(), $externalId, $ctx->runId), TaskBoardEvents::TASK_REMOTE_ARCHIVED);
            } else {
                $this->eventDispatcher?->dispatch(new TaskRemoteRestoredEvent($task, $ctx->provider->id(), $externalId, $ctx->runId), TaskBoardEvents::TASK_REMOTE_RESTORED);
            }
        }
    }

    /** Clears the entity manager (memory stays flat) and loads the run's board, connection and actor again. */
    private function reload(SyncRunContext $ctx): void
    {
        $boardId      = $ctx->board->getId();
        $connectionId = $ctx->connection->getId();
        $actor        = $this->identity($ctx->actor);
        $this->entityManager->clear();
        $board      = $this->entityManager->find(TaskBoard::class, $boardId);
        $connection = $this->entityManager->find(TaskBoardConnection::class, $connectionId);
        if (!$board instanceof TaskBoard || !$connection instanceof TaskBoardConnection) {
            throw new LogicException('The board or its connection was removed during the sync.');
        }
        $ctx->board      = $board;
        $ctx->connection = $connection;
        if ($actor !== null) {
            $ctx->actor = $this->entityManager->find($actor[0], $actor[1]) ?? $ctx->actor;
        }
        $ctx->positions = [];
    }

    /** A caller may pass an actor detached by an earlier clear: the run records the managed instance. */
    private function attach(SyncRunContext $ctx): void
    {
        $ctx->actor = $this->attached($ctx->actor);
    }

    /** The managed instance of a mapped entity that is not managed (detached by a clear); else the object itself. */
    private function attached(object $actor): object
    {
        $identity = $this->identity($actor);
        if ($identity === null || $this->entityManager->contains($actor)) {
            return $actor;
        }

        return $this->entityManager->find($identity[0], $identity[1]) ?? $actor;
    }

    /**
     * The class and identifier of a managed entity; null for an object Doctrine does not map.
     *
     * @return array{0: class-string, 1: array<string, mixed>}|null
     */
    private function identity(object $entity): ?array
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($entity::class);
            $id       = $metadata->getIdentifierValues($entity);
        } catch (Throwable) {
            return null;
        }

        return $id === [] ? null : [$metadata->getName(), $id];
    }

    /** The actor as a managed entity for a user association (null when Doctrine does not map it). */
    private function managedUser(object $actor): ?object
    {
        $identity = $this->identity($actor);

        return $identity === null ? null : ($this->entityManager->getReference($identity[0], $identity[1]) ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(SyncRunContext $ctx): array
    {
        return [
            'bundle'   => 'nowo_task_board',
            'board_id' => $ctx->board->getId(),
            'run_id'   => $ctx->runId,
            'provider' => $ctx->provider->id(),
            'mode'     => $ctx->mode->value,
            'pages'    => $ctx->pagesDone,
            'created'  => $ctx->created,
            'updated'  => $ctx->updated,
            'archived' => $ctx->archived,
            'restored' => $ctx->restored,
            'skipped'  => $ctx->skipReadOnly + $ctx->skipElsewhere + $ctx->skipLocallyDeleted + $ctx->skipLocallyArchived + $ctx->skipRepeated,
            'files'    => $ctx->filesCopied,
        ];
    }

    /** A report of a run that did not start (no history row). */
    private function bare(TaskBoard $board, string $provider, SyncStatus $status, ?string $errorCode, string $runId = ''): SyncReport
    {
        $report = new SyncReport(
            runId: $runId,
            boardId: $board->getId(),
            provider: $provider,
            mode: SyncMode::Initial,
            status: $status,
            errors: $errorCode === null ? [] : [new SyncError($errorCode)],
        );
        if ($status === SyncStatus::Failed && $errorCode !== null && $runId === '') {
            $this->logger->warning('TaskBoard sync refused', ['bundle' => 'nowo_task_board', 'board_id' => $board->getId(), 'error_code' => $errorCode]);
        }

        return $report;
    }
}
