<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Closure;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\LockConflict;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;
use Nowo\TaskBoardBundle\Tree\TaskTreeNode;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\Tree\TaskTreeStoreInterface;
use Throwable;

/**
 * Since 2.20.0 (Beacon spec 234, T004): {@see TaskTreeStoreInterface} on `database.entity_manager` (or the private
 * manager). It opens the transaction, or joins the caller's (no savepoint, audit PX-M1); the task events wait in
 * {@see TaskEventDeferral} until the commit; the locked reads are `SELECT … FOR UPDATE` of DQL scalars, so they read the
 * committed row, never the identity map. Inside a caller's transaction a failure, a lock conflict included, reaches the
 * caller as it is (audit IG-L1, as `TaskMover`).
 *
 * Stateless (worker mode): a manager closed by a failed flush is reset through the registry.
 *
 * @internal the bundle's implementation; depend on {@see TaskTreeStoreInterface}
 */
final readonly class DoctrineOrmTaskTreeStore implements TaskTreeStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        private ?TaskEventDeferral $events = null,
    ) {
    }

    public function transactional(Closure $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $depth      = $connection->getTransactionNestingLevel();
        if ($depth > 0) {
            return $this->joined($work);
        }
        $connection->beginTransaction();
        $this->events?->begin();
        try {
            $result = $work();
            $connection->commit();
        } catch (Throwable $failure) {
            TransactionRollback::toLevel($connection, 0);
            $this->events?->rollBack();
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);
            if (LockConflict::is($failure)) {
                throw TaskTreeRefusedException::conflict();
            }

            throw $failure;
        }
        // Audit JY-L1: after the commit; a failing host listener never reports the saved write as failed.
        $this->events?->commit(false);

        return $result;
    }

    /**
     * Since audit PX-M1: inside a caller's transaction no savepoint is opened. Every refusal comes before the first write,
     * so it has nothing to undo; a failure after a write (or a lost lock: MySQL has then rolled the whole transaction
     * back) reaches the caller, which owns the transaction and rolls it back. The locks taken stay until its commit.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function joined(Closure $work): mixed
    {
        $this->events?->begin();
        try {
            $result = $work();
        } catch (Throwable $failure) {
            $this->events?->rollBack();

            throw $failure;
        }
        // Held while the caller defers its events too (the Task API, an import, `TaskEventDeferral::defer()`); otherwise
        // dispatched now (see TaskReparenterInterface: a host that opens its own transaction wraps it in defer()).
        $this->events?->commit(false);

        return $result;
    }

    public function lockedNode(string $taskId): ?TaskTreeNode
    {
        /** @var list<array{board: int|string, parent: int|string|null, revision: int|string}> $rows */
        $rows = $this->entityManager->createQuery('SELECT IDENTITY(t.board) AS board, IDENTITY(t.parent) AS parent, t.revision AS revision FROM ' . Task::class . ' t WHERE t.id = :id')
            ->setParameter('id', $taskId)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        return new TaskTreeNode($taskId, (string) $row['board'], $row['parent'] === null ? null : (string) $row['parent'], (int) $row['revision']);
    }

    public function lockedHeight(string $taskId, int $bound): int
    {
        $seen   = [$taskId => true];
        $level  = [$taskId];
        $height = 0;
        while ($height <= $bound) {
            /** @var list<int|string> $ids */
            $ids = $this->entityManager->createQuery('SELECT t.id FROM ' . Task::class . ' t WHERE IDENTITY(t.parent) IN (:ids)')
                ->setParameter('ids', $level)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();
            if ($ids === []) {
                break;
            }
            $level = [];
            foreach ($ids as $id) {
                if (isset($seen[(string) $id])) {
                    return TaskTreeLimits::UNBOUNDED;
                }
                $seen[(string) $id] = true;
                $level[]            = (string) $id;
            }
            ++$height;
        }

        return $height;
    }

    public function task(TaskTreeNode $node): Task
    {
        $task = $this->entityManager->find(Task::class, $node->id) ?? throw TaskTreeRefusedException::notFound();
        // A copy loaded before a concurrent writer committed (a kept-alive worker's identity map) starts from the row locked.
        if ($task->getRevision() !== $node->revision || $task->getParent()?->getId() !== $node->parentId || $task->getBoard()->getId() !== $node->boardId) {
            $this->entityManager->refresh($task);
        }

        return $task;
    }

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function parentChanged(Task $task, object $actor, ?Task $previousParent): void
    {
        $this->events?->dispatchSaved(new TaskUpdatedEvent($task, $actor, ['parent'], ['parent' => $previousParent]), TaskBoardEvents::TASK_UPDATED);
    }
}
