<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;

use function count;
use function sprintf;

/**
 * Since 2.1.0 (spec 214, FR-003, FR-006, FR-008): numbers a task when it is persisted, so every creation path gets one without
 * changing it (the manager, the importer, the sync and its checklists), in write order. A later write leaves the number alone.
 * A task whose board changes to another scope gets a new number there; its old ledger row keeps pointing at it (an alias).
 *
 * `onFlush` runs before the unit of work opens its transaction, so the reservation does not wait for the flush. The ledger row is
 * written right after the task's own write, in the flush's transaction: it never exists without the task, and it is never a managed
 * entity, which would stop the next flush once its task is deleted.
 */
final readonly class TaskNumberListener
{
    public function __construct(
        private TaskNumberScope $scope,
        private TaskNumberReserver $reserver,
    ) {
    }

    /**
     * Since 2.15.1 (REQ-SEC-006, audit OM TB-L2): refuses a new task whose scope's counter, as the database finds it, is
     * a look-alike scope's (`scope_key` still folding, before the UPGRADING ALTER). Thrown here, before the task is
     * scheduled, the manager keeps no pending insert; `onFlush` would be too late. PostgreSQL compares exactly.
     *
     * @throws TaskNumberScopeConflictException
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $task = $args->getObject();
        if (!$task instanceof Task || $task->getNumber() !== null) {
            return;
        }
        $connection = $args->getObjectManager()->getConnection();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }
        $key    = $this->scope->keyOf($task->getBoard());
        $stored = $connection->fetchOne(sprintf('SELECT scope_key FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), [$key]);
        if ($stored !== false && $stored !== $key) {
            // Since 2.16.0 (spec 234): the task is named, so a bulk write knows which of its items conflicts.
            throw TaskNumberScopeConflictException::forScope($key, $task->getId());
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager = $args->getObjectManager();
        $work    = $manager->getUnitOfWork();

        /** @var array<string, list<Task>> $byScope */
        $byScope = [];
        foreach ($work->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Task && $entity->getNumber() === null) {
                $byScope[$this->scope->keyOf($entity->getBoard())][] = $entity;
            }
        }
        foreach ($work->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Task) {
                $old = $work->getEntityChangeSet($entity)['board'][0] ?? null;
                $key = $this->scope->keyOf($entity->getBoard());
                if ($old instanceof TaskBoard && $this->scope->keyOf($old) !== $key) {
                    $byScope[$key][] = $entity;
                }
            }
        }
        if ($byScope === []) {
            return;
        }

        $counts = [];
        foreach ($byScope as $key => $tasks) {
            $counts[$key] = count($tasks);
        }
        try {
            $first = $this->reserver->reserve($manager->getConnection(), $counts);
        } catch (TaskNumberScopeConflictException $conflict) {
            // Since 2.16.0 (spec 234): name the first task of the offending scope. The keys are compared byte for byte,
            // never through PHP's array keys (REQ-SEC-006), and only the caller's own tasks are listed.
            foreach ($byScope as $key => $tasks) {
                if ((string) $key === $conflict->scopeKey && $tasks !== []) {
                    throw $conflict->forTask($tasks[0]->getId());
                }
            }

            // The reserver is called with the keys of `$byScope`, so one always matches; this is the fail-safe.
            throw $conflict; // @codeCoverageIgnore
        }

        $metadata = $manager->getClassMetadata(Task::class);
        foreach ($byScope as $key => $tasks) {
            $number = $first[$key];
            foreach ($tasks as $task) {
                $task->assignNumber($number++);
                $work->recomputeSingleEntityChangeSet($metadata, $task);
            }
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $task = $args->getObject();
        if ($task instanceof Task && $task->getNumber() !== null) {
            $this->writeEntry($args->getObjectManager()->getConnection(), $task);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $task    = $args->getObject();
        $manager = $args->getObjectManager();
        if ($task instanceof Task) {
            // The board, not the number: the new number can equal the old one, and it still needs its row in the new scope.
            $old = $manager->getUnitOfWork()->getEntityChangeSet($task)['board'][0] ?? null;
            if ($old instanceof TaskBoard && $this->scope->keyOf($old) !== $this->scope->keyOf($task->getBoard())) {
                $this->writeEntry($manager->getConnection(), $task);
            }
        }
    }

    private function writeEntry(Connection $connection, Task $task): void
    {
        $connection->insert(TaskNumberEntry::TABLE, ['scope_key' => $this->scope->keyOf($task->getBoard()), 'number' => $task->getNumber(), 'task_id' => $task->getId()]);
    }
}
