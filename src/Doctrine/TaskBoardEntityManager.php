<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Closure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\Filter\Parameter;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function array_pop;
use function array_sum;
use function in_array;
use function is_a;
use function is_iterable;
use function is_object;
use function is_string;
use function ksort;
use function spl_object_id;
use function sprintf;

/**
 * TaskBoard's private entity manager (2.15.0, REQ-ARCH-001 rule 6; opt-in in 2.x, the only mode since 3.0.0). Adapted
 * from AuthKitBundle 1.46.0 (audits NV, NJ-2, NJV-1, NF-2, NF2-2, NF3-2, NF-7) with audit NZ's repository factory.
 *
 * Up to 2.14 the bundle wrote its rows through `database.entity_manager` (the application's `default` manager unless
 * configured): every TaskBoard save flushed whatever the application had left pending in that unit of work, and a failed
 * TaskBoard write closed the application's manager. Every TaskBoard service writes and reads through
 * this **private** manager, created on the connection, mapping, caches, proxies and event manager of the source manager
 * (`database.entity_manager`): the tables do not move, the bundle's Doctrine listeners (table prefix, task numbers) and
 * the host's listeners still run, and a TaskBoard flush never commits an application change. The connection is shared,
 * so a transaction the application opened includes the TaskBoard rows (the bundle's own transactions become savepoints).
 *
 * The object is a stable decorator (the repositories and services keep it); the wrapped manager is resolved on every call
 * and created again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) undid rows it wrote inside a transaction, so its identity map may describe
 *   rows that no longer exist ({@see PrivateEntityManagerMiddleware}; DBAL 4 only, with DBAL 3 the manager is only
 *   replaced at the next boundary). A stale manager that still has scheduled work is kept until that work is flushed or
 *   the next boundary;
 * - {@see reset()} ran: at every outermost unit-of-work boundary (the bundle's
 *   {@see TaskBoardRequestBoundarySubscriber}: start and end of the main request,
 *   of a Messenger message and of the outermost console command) and at `kernel.reset`. Changes still pending at that
 *   moment are dropped and logged as a warning (entity classes and counts, never a value).
 *
 * Host objects reached from TaskBoard rows (the `user_class` creator, author, member, ...) are adopted before each flush:
 * an instance the private manager does not hold (e.g. the security user, managed by the application's manager) is
 * replaced on the TaskBoard row by the private manager's reference to the same id, so Doctrine never mistakes it for a
 * new entity. The application's instance is never written.
 *
 * The SQL filters enabled on the source manager, with their parameters, are mirrored on every call.
 *
 * @internal
 */
final class TaskBoardEntityManager extends EntityManagerDecorator implements ResetInterface
{
    private ?EntityManagerInterface $current = null;

    /** A rollback undid rows the private manager wrote since it was created or last replaced. */
    private bool $stale = false;

    /**
     * Transaction nesting levels of the connection in which the private manager wrote (flush, bulk DQL / DBAL, refresh)
     * or loaded rows that nobody committed yet (audits NJ-2, NJV-1: only a rollback of one of these levels makes the
     * identity map stale).
     *
     * @var array<int, true>
     */
    private array $uncommitted = [];

    /**
     * Repositories that are not container services, bound to this decorator (stable across the replacements of the
     * private manager): a factory keeping one per private manager created would make memory grow in a kept-alive kernel.
     * Bounded by the number of mapped classes asked for; emptied by {@see reset()} with the manager.
     *
     * @var array<class-string, EntityRepository<object>>
     */
    private array $repositories = [];

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry (the source manager is resolved per call)
     * @param string $sourceManagerName `database.entity_manager` (the manager 2.14 wrote the TaskBoard rows through)
     * @param LoggerInterface|null $logger warns when a boundary drops pending changes
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $sourceManagerName = 'default',
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();
            // Same mapping, caches and proxies, but a repository factory owned by this manager (audit NZ): the shared factory
            // keeps one repository per manager instance for the life of the process, so every replaced private manager
            // would stay in memory; service repositories still come from the source factory.
            $configuration = clone $source->getConfiguration();
            $configuration->setRepositoryFactory(new PrivateManagerRepositoryFactory($configuration->getRepositoryFactory()));
            // @igor-ignore - Bundle-owned unit of work: dropped at every boundary, replaced when closed, stale or on another connection.
            $current = $this->current = new EntityManager($source->getConnection(), $configuration, $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
            // @igor-ignore - Bundle-owned bookkeeping, reset when the manager is replaced.
            $this->uncommitted = [];
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * Whether a private manager exists right now (no side effect; tests and the boundary subscriber).
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect; tests).
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * A transaction (level 1) or savepoint (its level; null when the level is unknown) was rolled back on the
     * connection (told by {@see PrivateEntityManagerConnection}). The manager becomes stale only when the rollback
     * undid rows it wrote (audit NJ-2: an unrelated rollback of the application, or of an TaskBoard savepoint, leaves it
     * alone); a stale manager is replaced at its next use unless it has scheduled work. It is never cleared here (the
     * rollback may run inside the private manager's own failing flush).
     */
    public function connectionRolledBack(?int $level = 1): void
    {
        $undone = false;
        foreach (array_keys($this->uncommitted) as $written) {
            if ($level === null || $written >= $level) {
                $undone = true;
                // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
                unset($this->uncommitted[$written]);
            }
        }
        if ($undone && $this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * The outermost transaction was committed: every row the private manager wrote is durable.
     */
    public function connectionCommitted(): void
    {
        // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
        $this->uncommitted = [];
    }

    /**
     * A savepoint of this level was released: its rows now belong to the level below.
     */
    public function savepointReleased(int $level): void
    {
        if (isset($this->uncommitted[$level])) {
            // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
            unset($this->uncommitted[$level]);
            if ($level > 1) {
                // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
                $this->uncommitted[$level - 1] = true;
            }
        }
    }

    /**
     * Service repositories (`ServiceEntityRepositoryInterface`) come from the source configuration's factory (audit NZ);
     * every other repository is built on this decorator (stable across the replacements of the private manager) and kept
     * until {@see reset()}: bounded by the number of mapped classes the bundle asks for.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return EntityRepository<T>
     */
    public function getRepository(string $className): EntityRepository
    {
        $live     = $this->live();
        $metadata = $live->getClassMetadata($className);
        $class    = $metadata->customRepositoryClassName;
        if ($class !== null && is_a($class, ServiceEntityRepositoryInterface::class, true)) {
            return $live->getRepository($className);
        }

        /** @var EntityRepository<T> $repository */
        $repository = $this->repositories[$metadata->getName()] ??= PrivateManagerRepositoryFactory::create($this, $metadata, $class ?? $live->getConfiguration()->getDefaultRepositoryClassName());

        return $repository;
    }

    public function flush(): void
    {
        $live = $this->live();
        $this->adoptForeignReferences($live);
        $live->flush();
        $this->wrote();
    }

    public function refresh(object $object, LockMode|int|null $lockMode = LockMode::NONE): void
    {
        $this->live()->refresh($object, $lockMode);
        $this->wrote();
    }

    /**
     * A TaskBoard statement that bypassed the unit of work (bulk DQL, DBAL) wrote at the current level (audit NJV-1).
     */
    public function noteWrite(): void
    {
        $this->wrote();
    }

    /**
     * Rows loaded by the private manager inside a transaction may be the application's uncommitted writes: a rollback
     * of that level makes the identity map stale too (audit NJV-1). Doctrine `postLoad` listener; other managers'
     * loads are ignored.
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        if ($this->current instanceof EntityManagerInterface && $args->getObjectManager() === $this->current) {
            $this->wrote();
        }
    }

    public function wrapInTransaction(callable $func): mixed
    {
        try {
            return $this->live()->wrapInTransaction($func);
        } finally {
            $this->wrote();
        }
    }

    /**
     * Since 3.0.0 (audit lessons XS, W1-2): a TaskBoard row the application's manager holds (loaded through the
     * application's own manager or repository) is refused with a clear error instead of being inserted again as a new
     * row: TaskBoard rows are read and written through TaskBoard's services and repositories (or
     * `nowo_task_board.entity_manager`). Everything else is persisted by the private manager as before.
     *
     * @throws LogicException when the object is a TaskBoard row the application's manager holds
     */
    public function persist(object $object): void
    {
        $live = $this->live();
        if (!$live->contains($object) && TaskBoardManagerRegistry::isBundleEntity($object::class) && $this->heldByApplication($object)) {
            throw new LogicException(sprintf('TaskBoard: the %s handed to TaskBoard was loaded through the application\'s entity manager "%s", which TaskBoard no longer writes (3.0: the private entity manager is the only mode). Load it through TaskBoard\'s services or repositories, or reference it by id (see UPGRADING.md "To 3.0.0", "Private manager only").', TaskBoardManagerRegistry::realClass($object::class), $this->sourceManagerName));
        }
        $live->persist($object);
    }

    /**
     * Since 3.0.0 (audit ZAS-1 / ZAS-2): the private manager's own copy of a TaskBoard row the application's manager holds
     * (loaded through the application's manager or repository), read again by id, so a TaskBoard service never changes
     * the application's instance or its collections (the application's next flush would then write what TaskBoard
     * refused, or fail on the new row). Anything else (a row the private manager holds, a new row, a non-TaskBoard
     * object) is returned as it is. The public write services call it at their entry (ChatKit `attach()` pattern).
     *
     * @template T of object
     *
     * @param T $row
     *
     * @throws LogicException when the row is not saved yet (since audit ZAS-V2) or no longer exists
     *
     * @return T
     */
    public function attach(object $row): object
    {
        $live = $this->live();
        if ($live->contains($row) || !TaskBoardManagerRegistry::isBundleEntity($row::class) || !$this->heldByApplication($row)) {
            return $row;
        }
        $uow = $this->source()->getUnitOfWork();
        // Since 3.0.0 (audit ZAS-V2): a row the application persisted but did not flush has no TaskBoard copy yet.
        if ($uow->isScheduledForInsert($row)) {
            throw new LogicException(sprintf('TaskBoard: the %s handed to TaskBoard is held by the application\'s entity manager "%s" and not saved yet. Flush it first, or create it through TaskBoard\'s services.', TaskBoardManagerRegistry::realClass($row::class), $this->sourceManagerName));
        }
        $class = $live->getClassMetadata($row::class)->getName();
        /** @var T|null $own */
        $own = $live->find($class, $uow->getEntityIdentifier($row));

        return $own ?? throw new LogicException(sprintf('TaskBoard: the %s handed to TaskBoard (loaded through the application\'s entity manager "%s") no longer exists.', TaskBoardManagerRegistry::realClass($row::class), $this->sourceManagerName));
    }

    /**
     * Host objects on TaskBoard rows that the private manager does not hold (the security user, a user the application
     * loaded) become the private manager's reference to the same id, so Doctrine never takes them for new entities. Since
     * 3.0.0 a TaskBoard row the application's manager holds, on a to-one of a TaskBoard row, is re-resolved the same way
     * (by its id, never inserted again). Only to-one associations of TaskBoard entities are looked at.
     *
     * Since 3.0.0 (audit lessons XS-I1 / XS-I2) an object that cannot be referenced fails before anything is written,
     * with a message that says what to do: an application entity not saved yet (scheduled for insert in the
     * application's manager, or without an identifier: flush it first), an object that is not of the association's class,
     * or a reference that cannot be found (a row of an inheritance hierarchy that no longer exists).
     *
     * @throws LogicException when a to-one value of a TaskBoard row cannot be referenced in the private manager
     */
    private function adoptForeignReferences(EntityManagerInterface $manager): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $pending    = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                $pending[] = $entity;
            }
        }
        // New rows the flush will cascade (a history entry added to a task, a member to a task, ...) are adopted too: they
        // are walked through the cascade-persist associations the flush would follow (never a lazy collection).
        $seen = [];
        while (($entity = array_pop($pending)) !== null) {
            $key = spl_object_id($entity);
            if (isset($seen[$key]) || !TaskBoardManagerRegistry::isBundleEntity($entity::class)) {
                continue;
            }
            $seen[$key] = true;
            $metadata   = $manager->getClassMetadata($entity::class);
            foreach ($metadata->associationMappings as $field => $mapping) {
                $value = $metadata->getFieldValue($entity, $field);
                if ($mapping->isToOne() && is_object($value) && !$manager->contains($value)
                    && (!TaskBoardManagerRegistry::isBundleEntity($mapping->targetEntity) || $this->heldByApplication($value))) {
                    $metadata->setFieldValue($entity, $field, $this->referenceOf($manager, $value, $mapping->targetEntity, $entity::class . '::$' . $field));

                    continue;
                }
                if (!$mapping->isCascadePersist() || $value === null) {
                    continue;
                }
                if ($value instanceof PersistentCollection && !$value->isInitialized()) {
                    continue;
                }
                foreach (is_iterable($value) ? $value : [$value] as $related) {
                    if (is_object($related) && !$manager->contains($related)) {
                        $pending[] = $related;
                    }
                }
            }
        }
    }

    /**
     * The private manager's reference to the row `$value` stands for (`$value` itself is never written).
     *
     * @param class-string $target the association's target class
     * @param string $where the TaskBoard field, for the message
     *
     * @throws LogicException when `$value` cannot be referenced
     */
    private function referenceOf(EntityManagerInterface $manager, object $value, string $target, string $where): object
    {
        if (!$value instanceof $target) {
            throw new LogicException(sprintf('TaskBoard: %s expects a %s, got a %s.', $where, $target, TaskBoardManagerRegistry::realClass($value::class)));
        }
        $source  = $this->source();
        $hostUow = $source->getUnitOfWork();
        if ($hostUow->isScheduledForInsert($value)) {
            throw new LogicException(sprintf('TaskBoard: the %s on %s is not saved yet (it is waiting for a flush of your entity manager "%s"). Flush it first, then call TaskBoard: TaskBoard writes through its own entity manager and references the row by id.', TaskBoardManagerRegistry::realClass($value::class), $where, $this->sourceManagerName));
        }
        $class = $manager->getClassMetadata($value::class);
        $id    = $source->contains($value) ? $hostUow->getEntityIdentifier($value) : $class->getIdentifierValues($value);
        if ($id === [] || in_array(null, $id, true)) {
            throw new LogicException(sprintf('TaskBoard: the %s on %s has no identifier: save it (persist and flush it with your entity manager) before you hand it to TaskBoard.', TaskBoardManagerRegistry::realClass($value::class), $where));
        }

        // A class with subclasses is looked up (Doctrine needs its discriminator): a row that no longer exists is null.
        return $manager->getReference($class->getName(), $id) ?? throw new LogicException(sprintf('TaskBoard: the %s on %s no longer exists.', TaskBoardManagerRegistry::realClass($value::class), $where));
    }

    /**
     * Whether the application's manager holds `$object` (a TaskBoard row it loaded itself), without loading anything.
     */
    private function heldByApplication(object $object): bool
    {
        $source = $this->source();

        return $source->getUnitOfWork() !== $this->current?->getUnitOfWork() && $source->contains($object);
    }

    /**
     * Records the nesting level the private manager's last write is waiting in (none outside a transaction).
     */
    private function wrote(): void
    {
        $level = $this->current?->getConnection()->getTransactionNestingLevel() ?? 0;
        if ($level > 0) {
            // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
            $this->uncommitted[$level] = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one (the bundle's batch writes call it to keep memory flat).
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager (a unit-of-work boundary or `kernel.reset`); the next call creates a fresh one.
     * Changes still pending are lost: they are logged as a warning with their entity classes and counts. The manager is
     * dropped FIRST, so a failing inspection or clear never keeps it across the boundary.
     */
    public function reset(): void
    {
        $current = $this->current;
        // Dropped first, so a failure below can never keep a manager across the boundary (audit NF2-2).
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->uncommitted  = [];
        $this->repositories = [];
        if (!$current instanceof EntityManagerInterface || !$current->isOpen()) {
            return;
        }

        try {
            $this->warnAboutDroppedChanges($current);
        } catch (Throwable $exception) {
            // Computing the change sets can throw (invalid association, detached or half-built entity).
            $this->logGenericWarning($exception);
        }

        try {
            $current->clear();
        } catch (Throwable $exception) {
            // A host onClear listener that throws must not abort the boundary (audit NF3-2): the manager is dropped.
            $this->logGenericWarning($exception);
        }
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset by the bundle.
     *
     * @throws LogicException when the source is not an ORM manager or does not map the TaskBoard entities
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->sourceManagerName);
        if (!$source instanceof EntityManagerInterface || $source->getMetadataFactory()->isTransient(Task::class)) {
            throw new LogicException(sprintf('The Doctrine entity manager "%s" (nowo_task_board.database.entity_manager, the source of the private entity manager) does not map %s.', $this->sourceManagerName, Task::class));
        }

        return $source;
    }

    /**
     * The fallback warning of {@see reset()}; a failing logger never breaks the boundary.
     */
    private function logGenericWarning(Throwable $exception): void
    {
        try {
            $this->logger?->warning('TaskBoard: the bundle\'s private entity manager was dropped at a unit-of-work boundary; its pending changes could not be inspected or cleared ({exception}).', [
                'bundle'    => 'nowo_task_board',
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Nothing else to report to: the manager is dropped anyway.
        }
    }

    /**
     * Safety net (audits NF-2, ND-L2): a boundary that drops the private manager while it still has inserts, updates or
     * deletes waiting (a TaskBoard entity changed and never saved through the bundle) logs them, per entity class.
     */
    private function warnAboutDroppedChanges(EntityManagerInterface $manager): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                $class           = TaskBoardManagerRegistry::realClass($entity::class);
                $changes[$class] = ($changes[$class] ?? 0) + 1;
            }
        }
        if ($changes === []) {
            return;
        }

        ksort($changes);
        $this->logger->warning('TaskBoard: {count} pending change(s) of the bundle\'s private entity manager were dropped at a unit-of-work boundary. Write TaskBoard rows through the TaskBoard services; the application\'s flush() does not write them.', [
            'bundle'  => 'nowo_task_board',
            'count'   => array_sum($changes),
            'changes' => $changes,
        ]);
    }

    /**
     * Scheduled inserts, updates or deletes, including changes not computed yet (a managed entity edited in memory).
     */
    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }

    /**
     * Mirrors the SQL filters enabled on the source manager, with their parameters, on the private one, and disables
     * the others. A filter whose parameters differ (its state string) is disabled and enabled again (a fresh instance)
     * before its parameters are copied, so a parameter the source no longer has (a previous tenant) never survives.
     * The parameters are read as they are stored (audit NF-7): values, list flag and the list type are kept.
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        // The private manager runs on a copy of the source configuration (its own repository factory): a filter the
        // application registered after the copy was made is added to the copy before it is enabled.
        $sourceConfiguration = $source->getConfiguration();
        $targetConfiguration = $target->getConfiguration();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                $filters->disable($name);
            }
            $class = $sourceConfiguration->getFilterClassName($name);
            if ($class !== null && $targetConfiguration->getFilterClassName($name) === null) {
                $targetConfiguration->addFilter($name, $class);
            }
            $copy = $filters->enable($name);
            foreach ($this->filterParameters($filter) as $parameter => $value) {
                if ($value->isList) {
                    /** @var array<mixed> $list */
                    $list = $value->value;
                    $copy->setParameterList($parameter, $list, is_string($value->type) ? $value->type : Types::STRING);
                } else {
                    // SQLFilter::setParameter() takes a string type or null: a non-string type was inferred, null infers it again.
                    $copy->setParameter($parameter, $value->value, is_string($value->type) ? $value->type : null);
                }
            }
        }
    }

    /**
     * The raw parameters of a filter (SQLFilter keeps them private and only exposes quoted values).
     *
     * @return array<string, Parameter>
     */
    private function filterParameters(SQLFilter $filter): array
    {
        $read = static fn (SQLFilter $filter): array => $filter->parameters; // @phpstan-ignore property.private (bound to SQLFilter below)
        /** @var array<string, Parameter> $parameters */
        $parameters = Closure::bind($read, null, SQLFilter::class)($filter);

        return $parameters;
    }
}
