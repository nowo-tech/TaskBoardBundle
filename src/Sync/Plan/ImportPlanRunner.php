<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TaskBoardBundle\Doctrine\BundleEntityDetacher;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Port\Internal\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Dto\SyncOptions;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Dto\SyncTrigger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Event\ImportPlanEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_filter;
use function array_map;
use function basename;
use function count;
use function implode;
use function in_array;
use function intdiv;
use function is_int;
use function max;
use function min;
use function random_int;

/**
 * Since 3.2.0 (CU-2, design §9.2, §13, §14): one slice of a started import plan. The plan is claimed with a
 * compare-and-set (one worker per plan; a heartbeat older than ten minutes is taken over), then its items run one after
 * the other — the board was created at start; the runner links it to its source (with the plan's status map, options
 * and people directory) and runs its connection's slices through {@see TaskBoardSyncService::runLinked()}, so a plan item
 * is an ordinary board run (board lock, cursor, history).
 *
 * How a slice ends: the slice's time is used up or the board's run paused (`paused`, due now), the token's budget or
 * ClickUp's limit (`rate_limited`, due after the wait plus 0–20 % jitter), a provider outage (`paused`, due after 1, 2,
 * 4, 8, 15 minutes; then the item fails), the people step (`paused` until *Resume*), a connection that no longer works or
 * an actor who may no longer act (`failed`), or every item done (`succeeded`; failed items are counted, the summary
 * offers to retry them). A per-item failure never stops the plan.
 *
 * Stateless: the plan and its items are read again at every slice; the private manager is cleared between items.
 */
final readonly class ImportPlanRunner
{
    /** A running plan whose heartbeat is older than this is taken over. */
    private const STALE_AFTER = 'PT10M';

    /** Codes of a connection that no longer works: the whole plan fails (`failed:credential`). */
    private const CREDENTIAL_CODES = ['auth_failed', 'credential_unavailable', 'unsupported_credential'];

    /** Codes of an outage: the plan pauses and resumes later. */
    private const TRANSIENT_CODES = ['server_error', 'timeout', 'transport', 'internal'];

    private const MAX_TRANSIENT_RETRIES = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportPlanStore $store,
        private SyncStore $syncStore,
        private TaskBoardSyncService $sync,
        private TaskProviderRegistry $providers,
        private ProviderCredentialResolverInterface $credentials,
        private TaskBoardRepositoryInterface $boards,
        private SyncActorResolverInterface $actors,
        private ?TaskSyncPeopleDirectoryService $people = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        private bool $timeSink = false,
        private int $keepDays = 90,
    ) {
    }

    /**
     * Runs one slice of at most `$sliceSeconds` and returns the plan's state after it; null when nothing ran (no such
     * plan, a plan that ended or is not due yet, or one another worker holds), so a queued runner stops there.
     */
    public function runSlice(string $planId, ?string $actorId, int $sliceSeconds): ?ImportPlanStatus
    {
        $plan = $this->store->find($planId);
        if (!$plan instanceof TaskSyncImportPlan) {
            return null;
        }
        $now = $this->clock->now();
        if (!$plan->getStatus()->isActive() || ($plan->getStatus() !== ImportPlanStatus::Running && $plan->getResumeAt() instanceof DateTimeImmutable && $plan->getResumeAt() > $now)
            || ($plan->getStatus() === ImportPlanStatus::Paused && !$plan->getResumeAt() instanceof DateTimeImmutable)
            || !$this->store->claim($plan, $now, $now->sub(new DateInterval(self::STALE_AFTER)))) {
            return null;
        }
        $deadline = $now->getTimestamp() + max(0, $sliceSeconds);
        try {
            $this->work($plan, $actorId ?? $plan->getStartedById(), $deadline);
        } catch (Throwable $exception) {
            // Anything unexpected (the database): the plan pauses and is resumed later; nothing is lost.
            $this->logger->error('TaskBoard import plan slice failed', ['bundle' => 'nowo_task_board', 'plan_id' => $planId, 'exception' => $exception::class, 'at' => basename($exception->getFile()) . ':' . $exception->getLine()]);
            $plan = $this->store->find($planId);
            if ($plan instanceof TaskSyncImportPlan && $plan->getStatus() === ImportPlanStatus::Running) {
                $this->transient($plan, 'internal');
                $this->entityManager->flush();
            }
        }

        return $this->store->find($planId)?->getStatus();
    }

    private function work(TaskSyncImportPlan $plan, ?string $actorId, int $deadline): void
    {
        $planId  = $plan->getId();
        $itemIds = array_map(static fn (TaskSyncImportPlanItem $item): string => $item->getId(), $this->store->items($plan));
        foreach ($itemIds as $itemId) {
            // Read again at each item: the board's run clears the private manager.
            $plan = $this->store->find($planId) ?? $plan;
            // An active plan's items are never removed (the wizard edits drafts only).
            $item = $this->entityManager->find(TaskSyncImportPlanItem::class, $itemId) ?? throw new LogicException('An item of the running plan was removed.');
            if ($plan->isCancelRequested()) {
                $this->end($plan, ImportPlanStatus::Cancelled, null);

                return;
            }
            if ($item->getStatus() === ImportPlanItemStatus::Succeeded || $item->getStatus() === ImportPlanItemStatus::Failed) {
                continue;
            }
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                $plan->hold(ImportPlanStatus::Paused, $this->clock->now());
                $this->entityManager->flush();

                return;
            }
            if (!$this->runItem($plan, $item, $actorId, $deadline)) {
                return;
            }
            $this->store->heartbeat($plan, $this->clock->now());
            BundleEntityDetacher::detach($this->entityManager);
        }
        $plan  = $this->store->find($planId) ?? $plan;
        $items = $this->store->items($plan);
        $this->aggregate($plan, $items);
        $failed = count(array_filter($items, static fn (TaskSyncImportPlanItem $item): bool => $item->getStatus() === ImportPlanItemStatus::Failed));
        $this->end($plan, $failed === count($items) && $items !== [] ? ImportPlanStatus::Failed : ImportPlanStatus::Succeeded, $failed === count($items) && $items !== [] ? 'items_failed' : null);
    }

    /**
     * One item: link, then sync slices. False when the slice must end here (the plan was held or ended).
     */
    private function runItem(TaskSyncImportPlan $plan, TaskSyncImportPlanItem $item, ?string $actorId, int $deadline): bool
    {
        $board = $item->getBoardId() !== null ? $this->boards->findById($item->getBoardId()) : null;
        if (!$board instanceof TaskBoard || $board->getTenantKey() !== $plan->getTenantKey()) {
            return $this->failItem($item, 'board_unavailable');
        }
        $actor = $this->actors->actorFor($board, $actorId);
        if ($actor === null) {
            // Design §15: a disabled, erased or demoted user stops the plan; it never runs as someone else.
            $this->end($plan, ImportPlanStatus::Failed, 'actor');

            return false;
        }
        $provider = $this->providers->has($plan->getProvider()) ? $this->providers->get($plan->getProvider()) : null;
        if (!$provider instanceof TaskProviderInterface) {
            $this->end($plan, ImportPlanStatus::Failed, 'provider_unavailable');

            return false;
        }
        $connection = $this->syncStore->findConnection($board);
        if ($item->getPhase() === ImportPlanPhase::Link || !$connection instanceof TaskBoardConnection) {
            $linked = $this->link($plan, $item, $board, $provider, $actor, $connection);
            if (!$linked instanceof TaskBoardConnection) {
                return $plan->getStatus() === ImportPlanStatus::Running;
            }
            if ($this->waitForPeople($plan, $item, $board, $provider)) {
                return false;
            }
        }
        $item->advance(ImportPlanItemStatus::Running, ImportPlanPhase::Sync);
        $this->entityManager->flush();
        $report = $this->sync->runLinked($board, new SyncOptions(
            actor: $actor,
            trigger: SyncTrigger::Manual,
            timeBudgetSeconds: max(1, $deadline - $this->clock->now()->getTimestamp()),
            timeEntries: $this->timeSink && $plan->option(TaskSyncImportPlan::OPTION_TIME, false),
            checklists: $plan->option(TaskSyncImportPlan::OPTION_CHECKLISTS, true),
            createOnly: $plan->getReimportMode() === ReimportMode::AddNewOnly,
        ));

        return $this->afterRun($plan, $item, $report);
    }

    /** Links the item's board to its source (or adopts the link the plan made before a crash). Null: not linked. */
    private function link(TaskSyncImportPlan $plan, TaskSyncImportPlanItem $item, TaskBoard $board, TaskProviderInterface $provider, object $actor, ?TaskBoardConnection $connection): ?TaskBoardConnection
    {
        if ($connection instanceof TaskBoardConnection) {
            if ($connection->getProvider() !== $plan->getProvider() || $connection->getContainerId() !== $item->getSourceId()) {
                $this->failItem($item, 'board_linked_elsewhere');

                return null;
            }
            $item->setConnectionId($connection->getId());

            return $connection;
        }
        $credential = $this->credentials->credentialFor($board, $plan->getConnectionRef());
        if (!$credential instanceof ProviderCredential) {
            $this->end($plan, ImportPlanStatus::Failed, 'credential');

            return null;
        }
        try {
            $container = $this->container($provider, $credential, $item->getSourceId());
        } catch (ProviderException $exception) {
            $this->providerFailure($plan, $item, $exception->errorCode->value);

            return null;
        }
        if (!$container instanceof RemoteContainer) {
            $this->failItem($item, 'not_found');

            return null;
        }
        $options = [
            'createMissingColumns'   => true,
            'importComments'         => $plan->option(TaskSyncImportPlan::OPTION_COMMENTS, true),
            'importAttachments'      => $plan->option(TaskSyncImportPlan::OPTION_ATTACHMENTS, true),
            'archiveRemoteDeletions' => true,
        ];
        if ($plan->getExcludedFields() !== []) {
            $options[TaskBoardConnection::OPTION_EXCLUDED_FIELDS] = implode("\n", $plan->getExcludedFields());
        }
        if ($plan->option(TaskSyncImportPlan::OPTION_UNMATCHED, false)) {
            $options['unmatchedColumns'] = TaskBoardConnection::UNMATCHED_DELETE;
        }
        $connection = $this->sync->link($board, $provider, $credential, $container, $actor, $options, $item->getStatusMap());
        $directory  = $plan->getDirectory();
        if ($directory instanceof TaskSyncDirectory) {
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $connection->setDirectory($this->entityManager->find(TaskSyncDirectory::class, $directory->getId()));
        }
        $item = $this->entityManager->find(TaskSyncImportPlanItem::class, $item->getId()) ?? $item;
        $item->setConnectionId($connection->getId());
        $item->advance(ImportPlanItemStatus::Running, ImportPlanPhase::Sync);
        $this->entityManager->flush();

        return $connection;
    }

    /**
     * The People option (§8.6): after the first board is linked the workspace's people are read into the plan's
     * directory and the plan waits (`paused`, no due time) until *Resume* — the People page of that board maps them.
     * Asked once per plan run.
     */
    private function waitForPeople(TaskSyncImportPlan $plan, TaskSyncImportPlanItem $item, TaskBoard $board, TaskProviderInterface $provider): bool
    {
        $directory = $plan->getDirectory();
        if (!$plan->option(TaskSyncImportPlan::OPTION_PEOPLE, false) || $plan->reportInt('people_waited') > 0 || !$directory instanceof TaskSyncDirectory
            || !$this->people instanceof TaskSyncPeopleDirectoryService || !$provider instanceof PeopleProviderInterface || $directory->getReviewedAt() instanceof DateTimeImmutable) {
            return false;
        }
        $credential = $this->credentials->credentialFor($board, $plan->getConnectionRef());
        $directory  = $this->entityManager->find(TaskSyncDirectory::class, $directory->getId());
        if ($credential instanceof ProviderCredential && $directory instanceof TaskSyncDirectory) {
            try {
                $this->people->refreshMembers($this->people->session($directory, $board), $provider, $credential);
            } catch (ProviderException) {
                // The People page reads them again.
            }
        }
        $plan = $this->store->find($plan->getId()) ?? $plan;
        $plan->setReport(['people_waited' => 1, 'people_board' => (string) $item->getBoardId()] + $plan->getReport());
        $plan->hold(ImportPlanStatus::Paused, null, 'people_mapping_required');
        $this->entityManager->flush();

        return true;
    }

    /** What the board's run means for the item and the plan; false when the slice must end. */
    private function afterRun(TaskSyncImportPlan $plan, TaskSyncImportPlanItem $item, SyncReport $report): bool
    {
        $plan = $this->store->find($plan->getId()) ?? $plan;
        $item = $this->entityManager->find(TaskSyncImportPlanItem::class, $item->getId()) ?? $item;
        if ($report->runId !== '') {
            $item->setRunId($report->runId);
        }
        $item->setReport($this->counts($report) + $item->getReport());
        $code = $report->errors[0]->code ?? $report->refusalCode;
        switch ($report->status) {
            case SyncStatus::Succeeded:
                $item->advance(ImportPlanItemStatus::Succeeded, ImportPlanPhase::Done);
                $plan->setReport(['transient_retries' => 0] + $plan->getReport());
                $this->entityManager->flush();

                return true;
            case SyncStatus::Paused:
                $plan->hold(ImportPlanStatus::Paused, $this->clock->now());
                break;
            case SyncStatus::Busy:
                // A manual refresh of the same board holds its lock: the item goes on later.
                $plan->hold(ImportPlanStatus::Paused, $this->clock->now()->modify('+30 seconds'), 'board_busy');
                break;
            case SyncStatus::RateLimited:
                $wait = max(1, $report->retryAfterSeconds ?? 60);
                $plan->hold(ImportPlanStatus::RateLimited, $this->clock->now()->modify('+' . ($wait + random_int(0, max(1, intdiv($wait, 5)))) . ' seconds'), 'rate_limited');
                break;
            default:
                // Failed, Refused or Cancelled: a connection failure stops the plan; an outage pauses it; anything else
                // fails the item and the plan goes on (a cancelled plan then ends before its next item).
                $this->entityManager->flush();

                return $this->providerFailure($plan, $item, $code ?? $report->status->value);
        }
        $this->entityManager->flush();

        return false;
    }

    /** A provider error of an item: the connection fails the plan, an outage pauses it, anything else fails the item. */
    private function providerFailure(TaskSyncImportPlan $plan, TaskSyncImportPlanItem $item, string $code): bool
    {
        if (in_array($code, self::CREDENTIAL_CODES, true)) {
            $this->end($plan, ImportPlanStatus::Failed, 'credential');

            return false;
        }
        if (in_array($code, self::TRANSIENT_CODES, true) && $plan->reportInt('transient_retries') < self::MAX_TRANSIENT_RETRIES) {
            $this->transient($plan, $code);
            $this->entityManager->flush();

            return false;
        }

        return $this->failItem($item, $code);
    }

    /** An outage: paused, due after 1, 2, 4, 8 then 15 minutes. */
    private function transient(TaskSyncImportPlan $plan, string $code): void
    {
        $retries = $plan->reportInt('transient_retries');
        $plan->setReport(['transient_retries' => $retries + 1] + $plan->getReport());
        $plan->hold(ImportPlanStatus::Paused, $this->clock->now()->modify('+' . min(900, 60 << $retries) . ' seconds'), $code);
    }

    private function failItem(TaskSyncImportPlanItem $item, string $code): bool
    {
        $item->advance(ImportPlanItemStatus::Failed, ImportPlanPhase::Done, $code);
        $this->entityManager->flush();
        $this->logger->warning('TaskBoard import plan item failed', ['bundle' => 'nowo_task_board', 'item_id' => $item->getId(), 'error_code' => $code]);

        return true;
    }

    /** A final state: the workspace is free; the event says how it ended; old plans are pruned (Q-CU-13). */
    private function end(TaskSyncImportPlan $plan, ImportPlanStatus $status, ?string $errorCode): void
    {
        $plan  = $this->store->find($plan->getId()) ?? $plan;
        $items = $this->store->items($plan);
        $this->aggregate($plan, $items);
        $plan->finish($status, $this->clock->now(), $errorCode);
        $this->entityManager->flush();
        $boards = [];
        foreach ($items as $item) {
            if ($item->getBoardId() !== null) {
                $boards[] = $item->getBoardId();
            }
        }
        $counts = [];
        foreach ($plan->getReport() as $key => $value) {
            if (is_int($value)) {
                $counts[$key] = $value;
            }
        }
        $this->eventDispatcher?->dispatch(
            new ImportPlanEvent($plan->getId(), $plan->getTenantKey(), $plan->getProvider(), $plan->getWorkspaceId(), $status->value, $plan->getStartedById(), $boards, $counts, $errorCode),
            $status === ImportPlanStatus::Failed ? TaskBoardEvents::SYNC_IMPORT_PLAN_FAILED : TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED,
        );
        $this->logger->info('TaskBoard import plan ended', ['bundle' => 'nowo_task_board', 'plan_id' => $plan->getId(), 'status' => $status->value, 'error_code' => $errorCode]);
        $this->store->prune($this->clock->now()->sub(new DateInterval('P' . $this->keepDays . 'D')));
    }

    /**
     * The plan's report: the sum of its items' counts, the failed items, and its own flags.
     *
     * @param list<TaskSyncImportPlanItem> $items
     */
    private function aggregate(TaskSyncImportPlan $plan, array $items): void
    {
        $report = ['items' => count($items), 'items_failed' => 0, 'items_done' => 0];
        foreach ($items as $item) {
            $report['items_failed'] += $item->getStatus() === ImportPlanItemStatus::Failed ? 1 : 0;
            $report['items_done'] += $item->getStatus() === ImportPlanItemStatus::Succeeded ? 1 : 0;
            foreach ($item->getReport() as $key => $value) {
                $report[$key] = ($report[$key] ?? 0) + $value;
            }
        }
        foreach (['people_waited', 'people_board', 'transient_retries'] as $flag) {
            if (isset($plan->getReport()[$flag])) {
                $report[$flag] = $plan->getReport()[$flag];
            }
        }
        $plan->setReport($report);
    }

    /**
     * @return array<string, int>
     */
    private function counts(SyncReport $report): array
    {
        return [
            'created'   => $report->created,
            'updated'   => $report->updated,
            'unchanged' => $report->unchanged,
            'archived'  => $report->archived,
            'skipped'   => $report->skipped(),
            'conflicts' => count($report->conflicts),
            'columns'   => $report->columnsCreated,
            'files'     => $report->files->copied,
            'refused'   => $report->files->refused,
            'processed' => $report->processed(),
            'total'     => $report->total ?? 0,
        ];
    }

    private function container(TaskProviderInterface $provider, ProviderCredential $credential, string $sourceId): ?RemoteContainer
    {
        if ($provider instanceof SourceProviderInterface) {
            $source = $provider->findSource($credential, $sourceId);

            return $source instanceof RemoteSource ? $source->container() : null;
        }

        return $provider->findContainer($credential, $sourceId);
    }
}
