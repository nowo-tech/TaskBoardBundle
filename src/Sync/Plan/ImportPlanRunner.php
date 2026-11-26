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
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
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
 * Since 3.2.0 (CU-2, design §9.2, §13, §14, §15, §19): one slice of a started import plan. The plan is claimed with a
 * compare-and-set that hands the slice a token (one worker per plan; a heartbeat older than ten minutes is taken over);
 * every later write of the slice — heartbeat, report, hold, end — is conditional on that token (audit ZBY-L2), so a
 * slice that was taken over or a plan that was cancelled meanwhile is never overwritten and never frees the workspace
 * lock. Then its items run one after the other — the board was created at start; the runner links it to its source
 * (with the plan's status map, options and people directory) and runs its connection's slices through
 * {@see TaskBoardSyncService::runLinked()}, so a plan item is an ordinary board run (board lock, cursor, history).
 *
 * The slice always acts as the user who started the plan (`started_by_id`), never as whoever polls or resumes it
 * (audit ZBY-M1): at every item it checks again that this user still exists, is enabled, may import plans in the tenant
 * (`import_plan`) and may connect and import into the board. If not — erased, disabled or demoted — the plan ends
 * `failed` with code `actor`; it never falls back to another user.
 *
 * How a slice ends: the slice's time is used up or the board's run paused (`paused`, due now), the token's budget or
 * ClickUp's limit (`rate_limited`, due after the wait plus 0–20 % jitter), a provider outage (`paused`, due after 1, 2,
 * 4, 8, 15 minutes; then the item fails), the people step (`paused` until *Resume*), a connection that no longer works or
 * an actor who may no longer act (`failed`), or every item done (`succeeded`; failed items are counted, the summary
 * offers to retry them). A per-item failure never stops the plan.
 *
 * Stateless: the plan and its items are read again at every slice; the private manager is cleared between items. The
 * plan row is only written through the store's conditional updates.
 */
final readonly class ImportPlanRunner
{
    /** A running plan whose heartbeat is older than this is taken over (a slice lasts at most 300 s). */
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
        private TaskBoardResourceAccessChecker $access,
        private ?TaskSyncPeopleDirectoryService $people = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        private bool $timeSink = false,
        private int $keepDays = 90,
    ) {
    }

    /**
     * Runs one slice of at most `$sliceSeconds` as the plan's starter and returns the plan's state after it; null when
     * nothing ran (no such plan, a plan that ended or is not due yet, or one another worker holds), so a queued runner
     * stops there.
     */
    public function runSlice(string $planId, int $sliceSeconds): ?ImportPlanStatus
    {
        // Read again: Resume, Cancel and other workers change the row behind this manager's back.
        $plan = $this->store->reload($planId);
        if (!$plan instanceof TaskSyncImportPlan) {
            return null;
        }
        $now   = $this->clock->now();
        $token = !$plan->getStatus()->isActive() || ($plan->getStatus() !== ImportPlanStatus::Running && $plan->getResumeAt() instanceof DateTimeImmutable && $plan->getResumeAt() > $now)
            || ($plan->getStatus() === ImportPlanStatus::Paused && !$plan->getResumeAt() instanceof DateTimeImmutable)
            ? null : $this->store->claim($plan, $now, $now->sub(new DateInterval(self::STALE_AFTER)));
        if ($token === null) {
            return null;
        }
        $deadline = $now->getTimestamp() + max(0, $sliceSeconds);
        try {
            $this->work($planId, $token, $deadline);
        } catch (Throwable $exception) {
            // Anything unexpected (the database, a host listener): the plan pauses and is resumed later; nothing is lost.
            $this->logger->error('TaskBoard import plan slice failed', ['bundle' => 'nowo_task_board', 'plan_id' => $planId, 'exception' => $exception::class, 'at' => basename($exception->getFile()) . ':' . $exception->getLine()]);
            $this->transient($this->plan($planId), $token, 'internal');
        }

        return $this->store->reload($planId)?->getStatus();
    }

    private function work(string $planId, string $token, int $deadline): void
    {
        $plan    = $this->plan($planId);
        $itemIds = array_map(static fn (TaskSyncImportPlanItem $item): string => $item->getId(), $this->store->items($plan));
        foreach ($itemIds as $itemId) {
            // Read again at each item: the board's run clears the private manager; the plan row may have changed.
            $plan = $this->plan($planId);
            // An active plan's items are never removed (the wizard edits drafts only).
            $item = $this->entityManager->find(TaskSyncImportPlanItem::class, $itemId) ?? throw new LogicException('An item of the running plan was removed.');
            if ($plan->isCancelRequested()) {
                $this->end($plan, $token, ImportPlanStatus::Cancelled, null);

                return;
            }
            if ($item->getStatus() === ImportPlanItemStatus::Succeeded || $item->getStatus() === ImportPlanItemStatus::Failed) {
                continue;
            }
            if ($this->clock->now()->getTimestamp() >= $deadline) {
                $this->hold($planId, $token, ImportPlanStatus::Paused, $this->clock->now(), null, $plan->getReport());

                return;
            }
            // A heartbeat refused: another slice took the plan over meanwhile; this one writes nothing more.
            if (!$this->runItem($plan, $token, $item, $deadline) || !$this->store->heartbeat($planId, $token, $this->clock->now())) {
                return;
            }
            BundleEntityDetacher::detach($this->entityManager);
        }
        $plan   = $this->plan($planId);
        $items  = $this->store->items($plan);
        $failed = count(array_filter($items, static fn (TaskSyncImportPlanItem $item): bool => $item->getStatus() === ImportPlanItemStatus::Failed));
        $this->end($plan, $token, $failed === count($items) && $items !== [] ? ImportPlanStatus::Failed : ImportPlanStatus::Succeeded, $failed === count($items) && $items !== [] ? 'items_failed' : null);
    }

    /**
     * One item: the actor is checked again, then link, then sync slices. False when the slice must end here (the plan
     * was held or ended).
     */
    private function runItem(TaskSyncImportPlan $plan, string $token, TaskSyncImportPlanItem $item, int $deadline): bool
    {
        $board = $item->getBoardId() !== null ? $this->boards->findById($item->getBoardId()) : null;
        if (!$board instanceof TaskBoard || $board->getTenantKey() !== $plan->getTenantKey()) {
            return $this->failItem($item, 'board_unavailable');
        }
        $actor = $this->actor($plan, $board);
        if ($actor === null) {
            $this->end($plan, $token, ImportPlanStatus::Failed, 'actor');

            return false;
        }
        $provider = $this->providers->has($plan->getProvider()) ? $this->providers->get($plan->getProvider()) : null;
        if (!$provider instanceof TaskProviderInterface) {
            $this->end($plan, $token, ImportPlanStatus::Failed, 'provider_unavailable');

            return false;
        }
        $connection = $this->syncStore->findConnection($board);
        if ($item->getPhase() !== ImportPlanPhase::Link && $connection instanceof TaskBoardConnection && $connection->getId() !== $item->getConnectionId()) {
            // Audit ZBY-I1: the board was linked again to something else since the plan linked it.
            return $this->failItem($item, 'board_linked_elsewhere');
        }
        if ($item->getPhase() === ImportPlanPhase::Link || !$connection instanceof TaskBoardConnection) {
            $linked = $this->link($plan, $token, $item, $board, $provider, $actor, $connection);
            if (!$linked instanceof TaskBoardConnection) {
                return $this->plan($plan->getId())->getRunToken() === $token;
            }
            if ($this->waitForPeople($plan, $token, $item, $board, $provider)) {
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

        return $this->afterRun($plan->getId(), $token, $item, $report);
    }

    /**
     * The plan's starter, when they may still run it on this board (audit ZBY-M1): resolved by id (an erased or
     * disabled user is none), allowed `import_plan` in the plan's tenant, and allowed to connect and import into the
     * board. Null otherwise — never another user.
     */
    private function actor(TaskSyncImportPlan $plan, TaskBoard $board): ?object
    {
        $starter = $plan->getStartedById();
        $actor   = $starter === null ? null : $this->actors->actorFor($board, $starter);

        return $actor !== null && $this->access->canImportPlan($actor, $plan->getTenantKey())
            && $this->access->canManageBoardConnection($actor, $board) && $this->access->canImportTasks($actor, $board) ? $actor : null;
    }

    /**
     * Links the item's board to its source, or adopts the link this plan made before a crash (same source, made since
     * the plan started). Null: not linked.
     */
    private function link(TaskSyncImportPlan $plan, string $token, TaskSyncImportPlanItem $item, TaskBoard $board, TaskProviderInterface $provider, object $actor, ?TaskBoardConnection $connection): ?TaskBoardConnection
    {
        if ($connection instanceof TaskBoardConnection) {
            if ($connection->getProvider() !== $plan->getProvider() || $connection->getContainerId() !== $item->getSourceId()
                || !$plan->getStartedAt() instanceof DateTimeImmutable || $connection->getCreatedAt() < $plan->getStartedAt()) {
                $this->failItem($item, 'board_linked_elsewhere');

                return null;
            }
            $item->setConnectionId($connection->getId());

            return $connection;
        }
        $credential = $this->credentials->credentialFor($board, $plan->getConnectionRef());
        if (!$credential instanceof ProviderCredential) {
            $this->end($plan, $token, ImportPlanStatus::Failed, 'credential');

            return null;
        }
        try {
            $container = $this->container($provider, $credential, $item->getSourceId());
        } catch (ProviderException $exception) {
            $this->providerFailure($plan, $token, $item, $exception->errorCode->value);

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
        // Since 3.3.0 (CU-3): the fields landed on native fields (`<remote id>=<create or native field id>` lines).
        $native = [];
        foreach ($plan->getNativeFields() as $remoteId => $target) {
            $native[] = $remoteId . '=' . $target;
        }
        if ($native !== []) {
            $options[TaskBoardConnection::OPTION_NATIVE_FIELDS] = implode("\n", $native);
        }
        if ($plan->option(TaskSyncImportPlan::OPTION_UNMATCHED, false)) {
            $options['unmatchedColumns'] = TaskBoardConnection::UNMATCHED_DELETE;
        }
        $connection = $this->sync->link($board, $provider, $credential, $container, $actor, $options, $item->getStatusMap());
        $directory  = $plan->getDirectory();
        if ($directory instanceof TaskSyncDirectory) {
            // @igor-ignore - Request data: the connection row this call created; not service state.
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
    private function waitForPeople(TaskSyncImportPlan $plan, string $token, TaskSyncImportPlanItem $item, TaskBoard $board, TaskProviderInterface $provider): bool
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
        $plan = $this->plan($plan->getId());
        $this->hold($plan->getId(), $token, ImportPlanStatus::Paused, null, 'people_mapping_required', ['people_waited' => 1, 'people_board' => (string) $item->getBoardId()] + $plan->getReport());

        return true;
    }

    /** What the board's run means for the item and the plan; false when the slice must end. */
    private function afterRun(string $planId, string $token, TaskSyncImportPlanItem $item, SyncReport $report): bool
    {
        $plan = $this->plan($planId);
        $item = $this->entityManager->find(TaskSyncImportPlanItem::class, $item->getId()) ?? $item;
        if ($report->runId !== '') {
            $item->setRunId($report->runId);
        }
        $item->setReport($this->counts($report) + $item->getReport());
        $code = $report->errors[0]->code ?? $report->refusalCode;
        if ($report->status === SyncStatus::Succeeded) {
            $item->advance(ImportPlanItemStatus::Succeeded, ImportPlanPhase::Done);
            $this->entityManager->flush();

            return $this->store->saveReport($planId, $token, ['transient_retries' => 0] + $plan->getReport());
        }
        $this->entityManager->flush();
        $now = $this->clock->now();
        switch ($report->status) {
            case SyncStatus::Paused:
                $this->hold($planId, $token, ImportPlanStatus::Paused, $now, null, $plan->getReport());

                return false;
            case SyncStatus::Busy:
                // A manual refresh of the same board holds its lock: the item goes on later.
                $this->hold($planId, $token, ImportPlanStatus::Paused, $now->modify('+30 seconds'), 'board_busy', $plan->getReport());

                return false;
            case SyncStatus::RateLimited:
                $wait = max(1, $report->retryAfterSeconds ?? 60);
                $this->hold($planId, $token, ImportPlanStatus::RateLimited, $now->modify('+' . ($wait + random_int(0, max(1, intdiv($wait, 5)))) . ' seconds'), 'rate_limited', $plan->getReport());

                return false;
            default:
                // Failed, Refused or Cancelled: a connection failure stops the plan; an outage pauses it; anything else
                // fails the item and the plan goes on (a cancelled plan then ends before its next item).
                return $this->providerFailure($plan, $token, $item, $code ?? $report->status->value);
        }
    }

    /** A provider error of an item: the connection fails the plan, an outage pauses it, anything else fails the item. */
    private function providerFailure(TaskSyncImportPlan $plan, string $token, TaskSyncImportPlanItem $item, string $code): bool
    {
        if (in_array($code, self::CREDENTIAL_CODES, true)) {
            $this->end($plan, $token, ImportPlanStatus::Failed, 'credential');

            return false;
        }
        if (in_array($code, self::TRANSIENT_CODES, true) && $plan->reportInt('transient_retries') < self::MAX_TRANSIENT_RETRIES) {
            $this->transient($plan, $token, $code);

            return false;
        }

        return $this->failItem($item, $code);
    }

    /** An outage: paused, due after 1, 2, 4, 8 then 15 minutes. */
    private function transient(TaskSyncImportPlan $plan, string $token, string $code): void
    {
        $retries = $plan->reportInt('transient_retries');
        $this->hold($plan->getId(), $token, ImportPlanStatus::Paused, $this->clock->now()->modify('+' . min(900, 60 << $retries) . ' seconds'), $code, ['transient_retries' => $retries + 1] + $plan->getReport());
    }

    private function failItem(TaskSyncImportPlanItem $item, string $code): bool
    {
        $item->advance(ImportPlanItemStatus::Failed, ImportPlanPhase::Done, $code);
        $this->entityManager->flush();
        $this->logger->warning('TaskBoard import plan item failed', ['bundle' => 'nowo_task_board', 'item_id' => $item->getId(), 'error_code' => $code]);

        return true;
    }

    /**
     * A final state, when this slice still holds the plan: the workspace is free; the event says how it ended; old plans
     * are pruned (Q-CU-13).
     */
    private function end(TaskSyncImportPlan $plan, string $token, ImportPlanStatus $status, ?string $errorCode): void
    {
        $items  = $this->store->items($plan);
        $report = $this->aggregate($plan, $items);
        if (!$this->store->finish($plan->getId(), $token, $status, $this->clock->now(), $errorCode, $report)) {
            return;
        }
        $boards = [];
        foreach ($items as $item) {
            if ($item->getBoardId() !== null) {
                $boards[] = $item->getBoardId();
            }
        }
        $counts = [];
        foreach ($report as $key => $value) {
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
     *
     * @return array<string, int|list<string>|string>
     */
    private function aggregate(TaskSyncImportPlan $plan, array $items): array
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

        return $report;
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

    /** The plan row as the database holds it now (the store's conditional updates bypass the identity map). */
    /**
     * A pause of the slice — unless a cancel was asked meanwhile: the plan then ends as cancelled instead of waiting
     * (audit ZBYv-L2). A slice that was taken over writes nothing either way (both writes are fenced).
     *
     * @param array<string, int|list<string>|string> $report
     */
    private function hold(string $planId, string $token, ImportPlanStatus $status, ?DateTimeImmutable $resumeAt, ?string $errorCode, array $report): void
    {
        if (!$this->store->hold($planId, $token, $status, $resumeAt, $errorCode, $report)) {
            $this->end($this->plan($planId), $token, ImportPlanStatus::Cancelled, null);
        }
    }

    private function plan(string $planId): TaskSyncImportPlan
    {
        return $this->store->reload($planId) ?? throw new LogicException('The running plan was removed.');
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
