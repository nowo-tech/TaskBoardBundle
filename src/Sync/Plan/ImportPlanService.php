<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use DateInterval;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Doctrine\BundleEntityDetacher;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Port\Internal\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\TenantCredentialResolverInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreatorInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Event\ImportPlanEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleDirectoryService;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\CustomFieldListProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderRegistry;
use Nowo\TaskBoardBundle\Sync\Provider\WorkspaceScope;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function substr;
use function trim;

/**
 * Since 3.2.0 (CU-2, design §8 and §9): the wizard of an import plan — each step validates, saves and moves the plan's
 * `version` on (a compare-and-set: a stale step is refused), then {@see self::start()} creates the new boards (one
 * transaction per board, with its item), takes the workspace lock and hands the plan to the runner.
 *
 * Every step works on the tenant's connection through the host's {@see TenantCredentialResolverInterface}; nothing here
 * keeps a credential. Stateless (worker-safe): every call loads what it needs.
 */
final readonly class ImportPlanService
{
    /** A container id of the provider's tree ("s:<id>", "f:<id>" or a list id). */
    public const CONTAINER_ID = '/^(?:[sf]:)?[A-Za-z0-9_-]{1,64}$/D';

    /** A provider field id. */
    private const FIELD_ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** Pages of one tree level read when a folder or a space is expanded. */
    private const MAX_LEVEL_PAGES = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportPlanStore $store,
        private SyncStore $syncStore,
        private TaskProviderRegistry $providers,
        private ProviderCredentialResolverInterface $credentials,
        private TaskBoardRepositoryInterface $boards,
        private TaskBoardCreatorInterface $boardCreator,
        private TaskBoardResourceAccessChecker $access,
        private TaskBoardAccessCheckerInterface $areaAccess,
        private ImportPlanRunnerInterface $runner,
        private TaskBoardQuota $quota = new TaskBoardQuota(),
        private ?TaskSyncPeopleDirectoryService $people = null,
        private ?TaskBoardSyncService $sync = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ClockInterface $clock = new NativeClock(),
        private int $maxItems = 200,
        private int $keepDays = 90,
    ) {
    }

    /** Whether the host offers tenant connections (else no plan page exists). */
    public function isAvailable(): bool
    {
        return $this->credentials instanceof TenantCredentialResolverInterface && $this->credentials->supportsTenants();
    }

    public function provider(TaskSyncImportPlan $plan): TaskProviderInterface
    {
        if (!$this->providers->has($plan->getProvider())) {
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }

        return $this->providers->get($plan->getProvider());
    }

    /**
     * A new draft of the tenant; also prunes the plans past their retention (Q-CU-13).
     */
    public function draft(?string $tenantKey, object $actor, string $provider): TaskSyncImportPlan
    {
        $now = $this->clock->now();
        $this->store->prune($now->sub(new DateInterval('P' . $this->keepDays . 'D')));
        $plan = new TaskSyncImportPlan($tenantKey, $provider, '', '', ConnectionCreatorActorResolver::idOf($actor), $now);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }

    /**
     * The tenant's connections of the plan's provider (the Connect step).
     *
     * @return list<ConnectionChoice>
     */
    public function connections(TaskSyncImportPlan $plan, object $actor): array
    {
        return $this->tenantCredentials()?->tenantConnectionsFor($this->tenant($plan), $actor, $plan->getProvider()) ?? [];
    }

    /** The host's page to paste a token, and its OAuth start (`$returnTo` = the plan's Connect step). */
    public function credentialFormUrl(TaskSyncImportPlan $plan): ?string
    {
        return $this->tenantCredentials()?->tenantCredentialFormUrl($this->tenant($plan), $plan->getProvider());
    }

    public function connectUrl(TaskSyncImportPlan $plan, string $returnTo): ?string
    {
        return $this->tenantCredentials()?->tenantConnectUrl($this->tenant($plan), $plan->getProvider(), $returnTo);
    }

    /** Step 1: the connection (another one than before clears the workspace and what follows). */
    public function chooseConnection(TaskSyncImportPlan $plan, int $version, ConnectionChoice $choice): void
    {
        $this->step($plan, $version, function (TaskSyncImportPlan $plan) use ($choice): void {
            if ($choice->ref !== $plan->getConnectionRef()) {
                $plan->useConnection($choice->ref, $choice->label);
                // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
                $this->store->removeItems($plan);
                $plan->rewindTo(ImportPlanStep::Workspace);
            }
            $plan->reach(ImportPlanStep::Connect);
        });
    }

    /**
     * The workspaces the connection may read (the tree's top level), narrowed to the ones it was authorised for.
     *
     * @return list<RemoteContainer>
     */
    public function workspaces(TaskSyncImportPlan $plan, object $actor): array
    {
        $choice = $this->choice($plan, $actor);
        try {
            $page = $this->provider($plan)->listContainers($this->credential($plan));
        } catch (ProviderException) {
            // Audit ZBY-L4: a provider error is a message on the step, never a 500.
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }

        return array_values(array_filter($page->items, static fn (RemoteContainer $item): bool => $item->type === ContainerType::Workspace
            && ($choice->workspaceIds === [] || in_array(self::workspaceIdOf($item->id), $choice->workspaceIds, true))));
    }

    /** Step 2: one workspace per plan; its people directory is the tenant's (Q-CU-6). */
    public function chooseWorkspace(TaskSyncImportPlan $plan, int $version, string $workspaceContainerId, object $actor): void
    {
        $workspace = null;
        foreach ($this->workspaces($plan, $actor) as $item) {
            if ($item->id === $workspaceContainerId) {
                $workspace = $item;
            }
        }
        if (!$workspace instanceof RemoteContainer) {
            throw new ImportPlanException(ImportPlanException::INVALID);
        }
        $this->step($plan, $version, function (TaskSyncImportPlan $plan) use ($workspace): void {
            $workspaceId = self::workspaceIdOf($workspace->id);
            if ($workspaceId !== $plan->getWorkspaceId()) {
                // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
                $plan->setWorkspace($workspaceId, $workspace->name);
                // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
                $this->store->removeItems($plan);
                $plan->rewindTo(ImportPlanStep::Tree);
                $provider = $this->provider($plan);
                // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
                $plan->setDirectory($this->people instanceof TaskSyncPeopleDirectoryService && $provider instanceof PeopleProviderInterface && $provider->capabilities()->people
                    ? $this->people->directoryForTenant($provider->id(), $plan->getTenantKey(), $workspaceId)
                    : null);
            }
            $plan->reach(ImportPlanStep::Workspace);
        });
    }

    /**
     * One level of the workspace's tree (the Tree step): the workspace's children, or a space's or folder's.
     *
     * @return list<RemoteContainer>
     */
    public function level(TaskSyncImportPlan $plan, ?string $parentId): array
    {
        $parent = $parentId ?? 'w:' . $plan->getWorkspaceId();
        if ($parentId !== null && preg_match(self::CONTAINER_ID, $parentId) !== 1) {
            throw new ImportPlanException(ImportPlanException::INVALID);
        }

        return $this->children($plan, $this->credential($plan), $parent);
    }

    /**
     * Step 3: the chosen containers become the plan's items by the layout (§8.3). Replaces the items chosen before.
     *
     * @param list<string> $containerIds
     * @param array<string, bool> $options comments, attachments, checklists, timeEntries, deleteUnusedColumns
     */
    public function chooseSources(TaskSyncImportPlan $plan, int $version, array $containerIds, ImportPlanLayout $layout, array $options): void
    {
        $ids = [];
        foreach ($containerIds as $id) {
            if (preg_match(self::CONTAINER_ID, $id) !== 1) {
                throw new ImportPlanException(ImportPlanException::INVALID);
            }
            $ids[$id] = true;
        }
        if ($ids === []) {
            throw new ImportPlanException(ImportPlanException::NO_SOURCES);
        }
        if ($layout === ImportPlanLayout::Single && count($ids) !== 1) {
            throw new ImportPlanException(ImportPlanException::SINGLE_NEEDS_ONE);
        }
        // Audit ZBYv-L1: each chosen container yields at least one item, so more than max_items is refused before any
        // provider call (the workspace check and the expansion cost calls from the token's shared budget).
        if (count($ids) > $this->maxItems) {
            throw new ImportPlanException(ImportPlanException::TOO_MANY_ITEMS, (string) $this->maxItems);
        }
        $credential = $this->credential($plan);
        $this->assertInWorkspace($plan, $credential, array_map(strval(...), array_keys($ids)));
        $sources = [];
        foreach ($ids as $id => $unused) {
            foreach ($this->expand($plan, $credential, $id, $layout) as $source) {
                $sources[$source->id] ??= $source;
                if (count($sources) > $this->maxItems) {
                    throw new ImportPlanException(ImportPlanException::TOO_MANY_ITEMS, (string) $this->maxItems);
                }
            }
        }
        $this->step($plan, $version, function (TaskSyncImportPlan $plan) use ($sources, $layout, $options): void {
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $this->store->removeItems($plan);
            $position = 0;
            foreach ($sources as $source) {
                $location = RemoteLocation::fromContainerId($source->id);
                $kind     = $location instanceof RemoteLocation ? $location->kind : RemoteLocationKind::List;
                $this->entityManager->persist(new TaskSyncImportPlanItem($plan, $position++, $kind->value, $source->id, $source->name, $source->path, $source->name));
            }
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $plan->setLayout($layout);
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $plan->setOptions($options);
            $plan->rewindTo(ImportPlanStep::Boards);
            $plan->reach(ImportPlanStep::Tree);
        });
    }

    /**
     * The tenant's boards an item may fill: the ones the actor may connect and import into, not linked to a source.
     *
     * @return list<TaskBoard>
     */
    public function targetBoards(TaskSyncImportPlan $plan, object $actor): array
    {
        $tenantKey = $plan->getTenantKey();
        $boards    = $tenantKey === null ? $this->boards->findAllActive() : $this->boards->findActiveByTenant($tenantKey);
        $targets   = [];
        foreach ($boards as $board) {
            if ($board->getTenantKey() === $tenantKey && $this->mayFill($board, $actor) && !$this->syncStore->findConnection($board) instanceof TaskBoardConnection) {
                $targets[] = $board;
            }
        }

        return array_slice($targets, 0, 200);
    }

    /**
     * Step 4: per item, a new board (its name) or an existing board of the tenant.
     *
     * @param array<string, array{target: string, name: string}> $choices item id => choice (`new` or a board id)
     */
    public function chooseBoards(TaskSyncImportPlan $plan, int $version, array $choices, object $actor): void
    {
        $allowed = [];
        foreach ($this->targetBoards($plan, $actor) as $board) {
            $allowed[$board->getId()] = $board;
        }
        $this->step($plan, $version, function (TaskSyncImportPlan $plan) use ($choices, $allowed): void {
            $used = [];
            foreach ($this->store->items($plan) as $item) {
                $choice = $choices[$item->getId()] ?? ['target' => 'new', 'name' => $item->getBoardName()];
                $name   = Text::cut(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $choice['name'])), 255);
                if ($choice['target'] === 'new') {
                    if (!$item->isCreateBoard()) {
                        $item->setStatusMap([]);
                    }
                    $item->targetNewBoard($name !== '' ? $name : $item->getSourceLabel());
                    continue;
                }
                $board = $allowed[$choice['target']] ?? null;
                if (!$board instanceof TaskBoard || isset($used[$board->getId()])) {
                    throw new ImportPlanException(ImportPlanException::BOARD_UNAVAILABLE);
                }
                $used[$board->getId()] = true;
                if ($item->getBoardId() !== $board->getId()) {
                    $item->setStatusMap([]);
                }
                $item->targetBoard($board->getId(), $board->getName());
            }
            $plan->reach(ImportPlanStep::Boards);
        });
    }

    /**
     * The status tables of the Statuses step: items whose source has the same statuses and that both fill new boards
     * share one table. Reads each source's statuses once (stored on the item).
     *
     * @return list<array{key: string, items: list<TaskSyncImportPlanItem>, statuses: list<RemoteStatus>, columns: array<string, string>}>
     */
    public function statusGroups(TaskSyncImportPlan $plan): array
    {
        $provider   = $this->provider($plan);
        $credential = null;
        $groups     = [];
        foreach ($this->store->items($plan) as $item) {
            $statuses = $item->getRemoteStatuses();
            if ($statuses === null) {
                $credential ??= $this->credential($plan);
                $statuses = $this->readStatuses($provider, $credential, $item);
                $item->setRemoteStatuses($statuses);
            }
            $board   = !$item->isCreateBoard() && $item->getBoardId() !== null ? $this->boards->findById($item->getBoardId()) : null;
            $columns = [];
            foreach ($board?->getColumns() ?? [] as $column) {
                $columns[$column->getId()] = $column->getName();
            }
            $key = hash('sha256', implode("\x1F", array_map(static fn (RemoteStatus $status): string => StatusColumns::normalize($status->name), $statuses)) . "\x1E" . ($board?->getId() ?? 'new'));
            $groups[$key] ??= ['key' => substr($key, 0, 16), 'items' => [], 'statuses' => $statuses, 'columns' => $columns];
            $groups[$key]['items'][] = $item;
        }
        $this->entityManager->flush();

        return array_values($groups);
    }

    /**
     * The column a status goes to when nothing was chosen yet: a same-named column of the board, else a new column.
     *
     * @param array<string, string> $columns column id => name
     */
    public static function defaultColumn(string $status, array $columns): string
    {
        foreach ($columns as $id => $name) {
            if (StatusColumns::normalize($name) === StatusColumns::normalize($status)) {
                return (string) $id;
            }
        }

        return StatusColumns::CREATE;
    }

    /**
     * Step 5: the status → column map of each table (a column of the target board, `__create` or `__skip`).
     *
     * @param array<string, array<int, string>> $choices group key => one column per status, in the group's order
     */
    public function mapStatuses(TaskSyncImportPlan $plan, int $version, array $choices): void
    {
        $groups = $this->statusGroups($plan);
        $this->step($plan, $version, function (TaskSyncImportPlan $plan) use ($groups, $choices): void {
            foreach ($groups as $group) {
                $posted = $choices[$group['key']] ?? [];
                $map    = [];
                foreach ($group['statuses'] as $index => $status) {
                    $column = $posted[$index] ?? self::defaultColumn($status->name, $group['columns']);
                    if (!in_array($column, [StatusColumns::CREATE, StatusColumns::SKIP], true) && !array_key_exists($column, $group['columns'])) {
                        throw new ImportPlanException(ImportPlanException::INVALID);
                    }
                    $map[] = ['status' => $status->name, 'column' => $column] + ($status->type !== null ? ['type' => $status->type->value] : []);
                }
                foreach ($group['items'] as $item) {
                    $item = $this->entityManager->find(TaskSyncImportPlanItem::class, $item->getId()) ?? $item;
                    $item->setStatusMap($map);
                }
            }
            $plan->reach(ImportPlanStep::Statuses);
        });
    }

    /**
     * The custom fields of the chosen sources (Fields step), with how many sources use each; empty when the provider
     * cannot list them.
     *
     * @return list<array{id: string, name: string, type: string, sources: int}>
     */
    public function fields(TaskSyncImportPlan $plan): array
    {
        $provider = $this->provider($plan);
        if (!$provider instanceof CustomFieldListProviderInterface || !$provider instanceof SourceProviderInterface) {
            return [];
        }
        $credential = $this->credential($plan);
        $fields     = [];
        try {
            foreach ($this->store->items($plan) as $item) {
                $source = $provider->findSource($credential, $item->getSourceId());
                foreach ($source instanceof RemoteSource ? $provider->listCustomFields($credential, $source) : [] as $field) {
                    $fields[$field->id] ??= ['id' => $field->id, 'name' => $field->name, 'type' => $field->type->value, 'sources' => 0];
                    ++$fields[$field->id]['sources'];
                }
            }
        } catch (ProviderException) {
            // Audit ZBY-L4: a provider error is a message on the step, never a 500.
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }

        return array_values($fields);
    }

    /**
     * Step 6: the fields not imported (the others are imported as synced, read-only fields; native fields are CU-3).
     *
     * @param list<string> $excluded
     */
    public function chooseFields(TaskSyncImportPlan $plan, int $version, array $excluded): void
    {
        foreach ($excluded as $id) {
            if (preg_match(self::FIELD_ID, $id) !== 1) {
                throw new ImportPlanException(ImportPlanException::INVALID);
            }
        }
        $this->step($plan, $version, static function (TaskSyncImportPlan $plan) use ($excluded): void {
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $plan->setExcludedFields(array_values(array_unique($excluded)));
            $plan->reach(ImportPlanStep::Fields);
        });
    }

    /** Step 7: whether the plan waits, after its first board is linked, until its people were mapped. */
    public function choosePeople(TaskSyncImportPlan $plan, int $version, bool $peopleFirst): void
    {
        $this->step($plan, $version, static function (TaskSyncImportPlan $plan) use ($peopleFirst): void {
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $plan->setOptions([TaskSyncImportPlan::OPTION_PEOPLE => $peopleFirst] + $plan->getOptions());
            $plan->reach(ImportPlanStep::People);
        });
    }

    /** Step 8 is the preview ({@see ImportPlanPreviewer}); saving it records the re-import mode. */
    public function confirmPreview(TaskSyncImportPlan $plan, int $version, ReimportMode $mode): void
    {
        $this->step($plan, $version, static function (TaskSyncImportPlan $plan) use ($mode): void {
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $plan->setReimportMode($mode);
            $plan->reach(ImportPlanStep::Preview);
        });
    }

    /**
     * Starts a draft (after its preview) or runs a finished plan again: *Re-import now* (every item again, idempotent)
     * or *Retry* (`$failedOnly`: the failed items). Checks the actor again, asks the host's board quota, creates the new
     * boards (one transaction per board with its item), takes the workspace lock (one active plan per tenant and
     * workspace) and dispatches the runner.
     *
     * @throws ImportPlanException
     */
    public function start(TaskSyncImportPlan $plan, int $version, object $actor, ?ReimportMode $mode = null, bool $failedOnly = false): void
    {
        $status = $plan->getStatus();
        if (($status === ImportPlanStatus::Draft && $plan->getStep()->position() < ImportPlanStep::Run->position()) || $status->isActive()) {
            throw new ImportPlanException(ImportPlanException::NOT_EDITABLE);
        }
        // Audit ZBY-I3: the connection must be one the host offers to this actor (another importer cannot run a plan
        // with a connection offered only to its creator).
        $this->choice($plan, $actor);
        $workspaceId = (string) $plan->getWorkspaceId();
        $items       = $this->store->items($plan);
        $newBoards   = count(array_filter($items, static fn (TaskSyncImportPlanItem $item): bool => $item->isCreateBoard()));
        if ($newBoards > 0 && !$this->mayCreateBoards($actor, $plan->getTenantKey())) {
            throw new ImportPlanException(ImportPlanException::BOARD_UNAVAILABLE);
        }
        $refusal = $newBoards > 0 ? $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, $plan->getTenantKey(), null, $newBoards, $actor) : null;
        if ($refusal !== null) {
            throw new ImportPlanException(ImportPlanException::QUOTA, $refusal);
        }
        $this->credential($plan);
        // The workspace lock and the version in one compare-and-set; another active plan of the workspace breaks the
        // unique key.
        try {
            $taken = (int) $this->entityManager->createQuery(
                'UPDATE ' . TaskSyncImportPlan::class . ' p SET p.lockKey = :lock, p.version = p.version + 1 WHERE p.id = :id AND p.version = :version AND p.lockKey IS NULL',
            )
                ->setParameter('lock', ImportPlanStore::lockKey($plan->getTenantKey(), $plan->getProvider(), $workspaceId))
                ->setParameter('id', $plan->getId())
                ->setParameter('version', $version)
                ->execute();
        } catch (UniqueConstraintViolationException) {
            throw new ImportPlanException(ImportPlanException::WORKSPACE_BUSY);
        }
        if ($taken !== 1) {
            throw new ImportPlanException(ImportPlanException::STALE);
        }
        $plan  = $this->reload($plan);
        $items = $this->store->items($plan);
        try {
            foreach ($items as $item) {
                $this->prepareItem($plan, $item, $actor, $failedOnly);
            }
        } catch (Throwable $exception) {
            // Nothing started: the workspace is free again; the boards already created stay with their items.
            $this->entityManager->createQuery('UPDATE ' . TaskSyncImportPlan::class . ' p SET p.lockKey = NULL WHERE p.id = :id')->setParameter('id', $plan->getId())->execute();

            throw $exception;
        }
        $actorId = ConnectionCreatorActorResolver::idOf($actor);
        if ($mode instanceof ReimportMode) {
            // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
            $plan->setReimportMode($mode);
        }
        $plan->markStarted($actorId, $this->clock->now(), (string) $plan->getLockKey());
        // @igor-ignore - Request data: the plan / item row read by id for this call, or a database write through the store; not service state.
        $plan->setReport(['people_waited' => $plan->reportInt('people_waited')]);
        $this->entityManager->flush();
        $this->eventDispatcher?->dispatch(new ImportPlanEvent($plan->getId(), $plan->getTenantKey(), $plan->getProvider(), $plan->getWorkspaceId(), ImportPlanStatus::Queued->value, $actorId, $this->boardIds($plan)), TaskBoardEvents::SYNC_IMPORT_PLAN_STARTED);
        $this->runner->dispatch(new PlanRunRequest($plan->getId()));
    }

    /**
     * Cancel (§14): a waiting plan ends now; a running one stops before its next item or instead of pausing, and the
     * board's run is asked to stop after its current page — one locked decision (audit ZBY-L2, ZBYv-L2). Nothing
     * imported is rolled back.
     */
    public function cancel(TaskSyncImportPlan $plan): void
    {
        // One decision under the row lock (audit ZBYv-L2): ended now, asked of the running slice, or nothing to cancel.
        $ended = $this->store->cancel($plan->getId(), $this->clock->now());
        if ($ended === null) {
            return;
        }
        foreach ($this->store->items($plan) as $item) {
            $board = $item->getRunId() !== null && $item->getBoardId() !== null ? $this->boards->findById($item->getBoardId()) : null;
            if ($board instanceof TaskBoard) {
                $this->sync?->requestCancel($board, (string) $item->getRunId());
            }
        }
        $plan = $this->reload($plan);
        if ($ended) {
            $this->eventDispatcher?->dispatch(new ImportPlanEvent($plan->getId(), $plan->getTenantKey(), $plan->getProvider(), $plan->getWorkspaceId(), ImportPlanStatus::Cancelled->value, $plan->getStartedById(), $this->boardIds($plan)), TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED);
        }
    }

    /**
     * Resumes a plan paused for its people (the People page was saved) or by a provider outage: due now (a
     * compare-and-set on its status). The slices still run as the user who started the plan, never as the one who
     * resumes it (audit ZBY-M1).
     */
    public function resume(TaskSyncImportPlan $plan): void
    {
        if ($this->store->makeDue($plan->getId(), $this->clock->now())) {
            $this->runner->dispatch(new PlanRunRequest($plan->getId()));
        }
    }

    /** Deletes a plan that is not running: its choices go, its boards and tasks stay. */
    public function delete(TaskSyncImportPlan $plan): void
    {
        $plan = $this->reload($plan);
        if ($plan->getStatus()->isActive()) {
            throw new ImportPlanException(ImportPlanException::NOT_EDITABLE);
        }
        $this->store->delete($plan);
    }

    /**
     * The tenant's credential of the plan's connection, for the wizard's reads.
     *
     * @throws ImportPlanException
     */
    public function credential(TaskSyncImportPlan $plan): ProviderCredential
    {
        $credential = $plan->getConnectionRef() === '' ? null : $this->tenantCredentials()?->tenantCredentialFor($this->tenant($plan), $plan->getConnectionRef());
        if (!$credential instanceof ProviderCredential) {
            throw new ImportPlanException(ImportPlanException::CREDENTIAL);
        }

        return $credential;
    }

    /** The plan's connection among the actor's (null when it is no longer offered). */
    public function choice(TaskSyncImportPlan $plan, object $actor): ConnectionChoice
    {
        foreach ($this->connections($plan, $actor) as $choice) {
            if ($choice->ref === $plan->getConnectionRef()) {
                return $choice;
            }
        }

        throw new ImportPlanException(ImportPlanException::CREDENTIAL);
    }

    /** "w:<id>" → "<id>". */
    public static function workspaceIdOf(string $containerId): string
    {
        return preg_match('/^w:(.+)$/D', $containerId, $match) === 1 ? $match[1] : $containerId;
    }

    /**
     * Whether the actor may fill the board: connect it and import into it (design §15).
     */
    public function mayFill(TaskBoard $board, object $actor): bool
    {
        return $this->access->canManageBoardConnection($actor, $board) && $this->access->canImportTasks($actor, $board);
    }

    /**
     * @return list<string>
     */
    public function boardIds(TaskSyncImportPlan $plan): array
    {
        return array_values(array_filter(array_map(static fn (TaskSyncImportPlanItem $item): ?string => $item->getBoardId(), $this->store->items($plan)), is_string(...)));
    }

    /**
     * An item before the run: a retry resets it, a new board is created now (in one transaction with the item), an
     * existing board is checked again (tenant, rights, not linked to another source).
     */
    private function prepareItem(TaskSyncImportPlan $plan, TaskSyncImportPlanItem $item, object $actor, bool $failedOnly): void
    {
        if (!$failedOnly || $item->getStatus() === ImportPlanItemStatus::Failed) {
            $item->reset();
        }
        if (!$item->isCreateBoard()) {
            $board = $item->getBoardId() !== null ? $this->boards->findById($item->getBoardId()) : null;
            if (!$board instanceof TaskBoard || $board->getTenantKey() !== $plan->getTenantKey() || !$this->mayFill($board, $actor)) {
                $item->advance(ImportPlanItemStatus::Failed, ImportPlanPhase::Done, 'board_unavailable');
            }
            $this->entityManager->flush();

            return;
        }
        // Audit ZBY-L3: the host's quota is asked again for each board, right before it is created (two plans started
        // at once both passed the check for all their boards).
        $refusal = $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, $plan->getTenantKey(), null, 1, $actor);
        if ($refusal !== null) {
            $item->advance(ImportPlanItemStatus::Failed, ImportPlanPhase::Done, 'quota');
            $this->entityManager->flush();

            return;
        }
        $db    = $this->entityManager->getConnection();
        $level = $db->getTransactionNestingLevel();
        $db->beginTransaction();
        try {
            $board = $this->boardCreator->create(new TaskBoardFormData(
                name: $item->getBoardName(),
                description: $item->getSourcePath() === [] ? null : implode(' › ', [...$item->getSourcePath(), $item->getSourceLabel()]),
            ), $actor, $plan->getTenantKey());
            $item->targetBoard($board->getId(), $board->getName());
            $this->entityManager->flush();
            $db->commit();
        } catch (Throwable $exception) {
            TransactionRollback::toLevel($db, $level);

            throw $exception;
        }
    }

    /**
     * Audit ZBY-L1: every chosen container belongs to the plan's workspace, asked from the provider when it can tell
     * ({@see WorkspaceScope}; ClickUp: the workspace's spaces once, then one call per list or folder — audit ZBYv-L1).
     * A container of another workspace would escape the workspace lock, the people directory and the host's workspace
     * narrowing.
     *
     * @param list<string> $ids
     */
    private function assertInWorkspace(TaskSyncImportPlan $plan, ProviderCredential $credential, array $ids): void
    {
        try {
            $foreign = WorkspaceScope::foreign($this->provider($plan), $credential, (string) $plan->getWorkspaceId(), $ids);
        } catch (ProviderException) {
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }
        if ($foreign !== []) {
            throw new ImportPlanException(ImportPlanException::INVALID);
        }
    }

    private function mayCreateBoards(object $actor, ?string $tenantKey): bool
    {
        return $this->areaAccess instanceof TenantAwareTaskBoardAccessCheckerInterface
            ? $this->areaAccess->canCreateBoard($actor, $tenantKey)
            : $this->areaAccess->canCreateBoard($actor);
    }

    /**
     * A wizard step as one unit (audit ZBY-L2): the version compare-and-set, the change and its flush in one
     * transaction; on any failure nothing of it stays (the private manager is cleared, so no half change is flushed
     * later).
     *
     * @param callable(TaskSyncImportPlan): void $change
     */
    private function step(TaskSyncImportPlan $plan, int $version, callable $change): void
    {
        $db    = $this->entityManager->getConnection();
        $level = $db->getTransactionNestingLevel();
        $db->beginTransaction();
        try {
            $change($this->lockStep($plan, $version));
            $this->entityManager->flush();
            $db->commit();
        } catch (Throwable $exception) {
            TransactionRollback::toLevel($db, $level);
            // The caller's copy shows the row as it is again (its version did not move); nothing half changed stays
            // in the private manager.
            $this->store->reload($plan->getId());
            BundleEntityDetacher::detach($this->entityManager);

            throw $exception;
        }
    }

    /**
     * The compare-and-set of a wizard step: only a draft at `$version` moves on (version + 1), else the step is stale.
     *
     * @throws ImportPlanException
     */
    private function lockStep(TaskSyncImportPlan $plan, int $version): TaskSyncImportPlan
    {
        $updated = $this->entityManager->createQuery(
            'UPDATE ' . TaskSyncImportPlan::class . ' p SET p.version = p.version + 1 WHERE p.id = :id AND p.version = :version AND p.status = :draft',
        )
            ->setParameter('id', $plan->getId())
            ->setParameter('version', $version)
            ->setParameter('draft', ImportPlanStatus::Draft->value)
            ->execute();
        if ((int) $updated !== 1) {
            throw new ImportPlanException(ImportPlanException::STALE);
        }

        return $this->reload($plan);
    }

    /**
     * The plan as the private manager holds it now (a caller may hold a copy an earlier clear detached), read again.
     */
    private function reload(TaskSyncImportPlan $plan): TaskSyncImportPlan
    {
        $managed = $this->store->find($plan->getId()) ?? throw new ImportPlanException(ImportPlanException::NOT_EDITABLE);
        $this->entityManager->refresh($managed);

        return $managed;
    }

    /**
     * What one chosen container becomes by the layout.
     *
     * @return list<RemoteContainer>
     */
    private function expand(TaskSyncImportPlan $plan, ProviderCredential $credential, string $id, ImportPlanLayout $layout): array
    {
        $self = $this->container($plan, $credential, $id);
        if ($layout === ImportPlanLayout::Single || $self->type === ContainerType::List || ($layout === ImportPlanLayout::PerFolder && $self->type === ContainerType::Folder)) {
            return [$self];
        }
        $expanded = [];
        foreach ($this->children($plan, $credential, $self->id) as $child) {
            if ($child->type === ContainerType::List) {
                $expanded[] = $child;
            } elseif ($child->type === ContainerType::Folder) {
                $folder   = new RemoteContainer($child->id, $child->name, ContainerType::Folder, $child->parentId, true, $child->hasChildren, $child->taskCount, $child->path === [] ? [$self->name] : $child->path);
                $expanded = $layout === ImportPlanLayout::PerFolder ? [...$expanded, $folder] : [...$expanded, ...$this->children($plan, $credential, $child->id)];
            }
        }

        return $expanded;
    }

    /** A chosen container, with its name and path (a list, a folder or a space). */
    private function container(TaskSyncImportPlan $plan, ProviderCredential $credential, string $id): RemoteContainer
    {
        $provider = $this->provider($plan);
        try {
            $source = $provider instanceof SourceProviderInterface ? $provider->findSource($credential, $id) : null;
            if ($source instanceof RemoteSource) {
                $type = match ($source->location->kind) {
                    RemoteLocationKind::List   => ContainerType::List,
                    RemoteLocationKind::Folder => ContainerType::Folder,
                    RemoteLocationKind::Space  => ContainerType::Space,
                };

                return new RemoteContainer($source->location->containerId(), $source->name, $type, null, true, $type !== ContainerType::List, $source->openTaskCount, $source->path);
            }
            $list = $provider->findContainer($credential, $id);
        } catch (ProviderException) {
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }
        if (!$list instanceof RemoteContainer) {
            throw new ImportPlanException(ImportPlanException::INVALID);
        }

        return $list;
    }

    /**
     * Every child of a tree node, page by page (bounded).
     *
     * @return list<RemoteContainer>
     */
    private function children(TaskSyncImportPlan $plan, ProviderCredential $credential, string $parentId): array
    {
        $provider = $this->provider($plan);
        $items    = [];
        $cursor   = null;
        try {
            for ($page = 0; $page < self::MAX_LEVEL_PAGES; ++$page) {
                $level  = $provider->listContainers($credential, $parentId, $cursor);
                $items  = [...$items, ...$level->items];
                $cursor = $level->nextCursor;
                if ($cursor === null) {
                    break;
                }
            }
        } catch (ProviderException) {
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }

        return $items;
    }

    /**
     * @return list<RemoteStatus>
     */
    private function readStatuses(TaskProviderInterface $provider, ProviderCredential $credential, TaskSyncImportPlanItem $item): array
    {
        if (!$provider instanceof SourceProviderInterface) {
            return [];
        }
        try {
            $source = $provider->findSource($credential, $item->getSourceId());

            return $source instanceof RemoteSource ? array_slice($provider->listStatuses($credential, $source), 0, 100) : [];
        } catch (ProviderException) {
            throw new ImportPlanException(ImportPlanException::PROVIDER);
        }
    }

    private function tenantCredentials(): ?TenantCredentialResolverInterface
    {
        return $this->isAvailable() && $this->credentials instanceof TenantCredentialResolverInterface ? $this->credentials : null;
    }

    private function tenant(TaskSyncImportPlan $plan): TenantRef
    {
        return new TenantRef($plan->getTenantKey());
    }
}
