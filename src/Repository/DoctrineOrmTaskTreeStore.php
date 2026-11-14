<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\LockConflict;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Tree\TaskReparentRefusedException;
use Nowo\TaskBoardBundle\Tree\TaskTreeNode;
use Nowo\TaskBoardBundle\Tree\TaskTreeStoreInterface;
use Throwable;

/**
 * Since 2.20.0 (Beacon spec 234, T004): {@see TaskTreeStoreInterface} on `database.entity_manager` (or the private
 * manager). The transaction is the connection's (nested: a savepoint), the task events wait in {@see TaskEventDeferral}
 * until the commit, and the locked reads are `SELECT … FOR UPDATE` of DQL scalars, so they read the committed row, never
 * the identity map. A failed write rolls back what it opened only; inside a caller's transaction a lock conflict reaches the
 * caller as it is (audit IG-L1, as `TaskMover`), since a MySQL deadlock has also rolled back the caller's earlier writes.
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
        $connection->beginTransaction();
        $this->events?->begin();
        try {
            $result = $work();
            $connection->commit();
        } catch (Throwable $failure) {
            $this->rollBack($connection, $depth);
            $this->events?->rollBack();
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);
            if ($depth === 0 && LockConflict::is($failure)) {
                throw TaskReparentRefusedException::conflict();
            }

            throw $failure;
        }
        // Audit JY-L1: after the commit; a failing host listener never reports the saved write as failed.
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

    public function task(TaskTreeNode $node): Task
    {
        $task = $this->entityManager->find(Task::class, $node->id) ?? throw TaskReparentRefusedException::notFound();
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

    /**
     * Rolls back the levels this call opened. When the server already ended the transaction (a MySQL deadlock), rolling
     * back to a savepoint fails: at the outermost level the connection is closed (the next query reconnects); inside a
     * caller's transaction it is left for the caller, which owns it.
     */
    private function rollBack(Connection $connection, int $depth): void
    {
        try {
            while ($connection->getTransactionNestingLevel() > $depth) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            if ($depth === 0) {
                $connection->close();
            }
        }
    }
}
