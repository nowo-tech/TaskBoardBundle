<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Closure;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Throwable;

/**
 * Since 2.16.0 (spec 234): one database transaction around a Task API write that saves in several steps (an update with
 * `base_revision`, a bulk update), with the task events held until the commit and dropped on a rollback.
 *
 * Worker mode (REQ-RUNTIME-002, audit OG-1): everything that can be refused is checked before the first write, so a
 * refusal rolls back nothing in memory. When a write fails anyway, the transaction is rolled back, a manager the failed
 * flush closed is reset through the registry, and the tasks the write touched are reloaded from the database (best
 * effort), so the next request of the process never sees a closed manager or a value the rollback removed. The host's
 * manager is never cleared. Stateless.
 */
final readonly class TaskApiTransaction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        private ?TaskEventDeferral $events = null,
    ) {
    }

    /**
     * @template T
     *
     * @param Closure(): T $write
     * @param list<Task> $touched reloaded after a rollback
     *
     * @return T
     */
    public function run(Closure $write, array $touched = []): mixed
    {
        $connection = $this->entityManager->getConnection();
        $depth      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        $this->events?->begin();
        try {
            $result = $write();
            $connection->commit();
        } catch (Throwable $exception) {
            // Audit PX-M1 (2.20.0; since 2.16.0): a failed rollback (MySQL ended the transaction on a deadlock, so the
            // savepoint is gone) never skips the clean-up, and never leaves the connection's nesting level behind.
            try {
                TransactionRollback::toLevel($connection, $depth);
            } finally {
                $this->events?->rollBack();
                RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);
                $this->reload($touched);
            }

            throw $exception;
        }
        // Audit JY-L1: after the commit; a failing host listener never reports the saved write as failed.
        $this->events?->commit(false);

        return $result;
    }

    /**
     * The task's revision in the database, its row locked until the transaction ends (inside {@see self::run()} only). A
     * task loaded before a concurrent writer committed is reloaded, so the write starts from the row it locked.
     */
    public function lockedRevision(Task $task): int
    {
        /** @var array{revision: int|string, parent: int|string|null} $row */
        $row = $this->entityManager->createQuery('SELECT t.revision AS revision, IDENTITY(t.parent) AS parent FROM ' . Task::class . ' t WHERE t.id = :id')
            ->setParameter('id', $task->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getSingleResult();
        $revision = (int) $row['revision'];
        $parent   = $row['parent'] === null ? null : (string) $row['parent'];
        // Since 2.20.0: also when only the parent differs (a write that bumped no revision), so `parent` compares as committed.
        if ($revision !== $task->getRevision() || $parent !== $task->getParent()?->getId()) {
            $this->entityManager->refresh($task);
            // Since 2.23.0 (audit TE-M1): inside an outer REPEATABLE READ transaction (MySQL / MariaDB) the refresh reads that
            // transaction's snapshot, not the row just locked; a write from it would undo the other writer's change. Never
            // write from a stale copy: 409 TASK_409 with the locked revision (read the task again).
            if ($task->getRevision() !== $revision || $task->getParent()?->getId() !== $parent) {
                throw TaskApiRefusal::stale($revision);
            }
        }

        return $revision;
    }

    /**
     * @param list<Task> $tasks
     */
    private function reload(array $tasks): void
    {
        if (!$this->entityManager->isOpen()) {
            // resetIfClosed() replaced it; the old manager's entities are gone with it.
            return; // @codeCoverageIgnore
        }
        foreach ($tasks as $task) {
            try {
                if ($this->entityManager->contains($task)) {
                    $this->entityManager->refresh($task);
                }
            } catch (Throwable) {
                // Best effort: a task the rollback removed, or a connection lost, leaves the next read to the database.
            }
        }
    }
}
