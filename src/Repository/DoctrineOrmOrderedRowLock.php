<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\OrderedRowLockInterface;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Throwable;

use function array_chunk;
use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_values;
use function sort;

use const SORT_STRING;

/**
 * Since 2.21.0 (Beacon spec 239, block B, FR-013): {@see OrderedRowLockInterface} on `database.entity_manager` (or the
 * private manager). The locks are DQL `SELECT … WHERE id IN (…) ORDER BY id FOR UPDATE` reads of the ids only: InnoDB
 * locks the rows as it walks the primary key, PostgreSQL as the sorted rows come out, so every transaction takes them in
 * the same order. The ids are sent in chunks, in order, so a large column keeps that order across statements.
 *
 * Inside a caller's transaction a failure reaches the caller as it is (audit IG-L1, as `TaskMover`). At the outermost
 * level the transaction is rolled back (the connection closed when the server already ended it, see
 * {@see TransactionRollback}) and a manager closed by a failed flush is reset through the registry. Stateless (worker mode).
 *
 * @internal the bundle's implementation; depend on {@see OrderedRowLockInterface}
 */
final readonly class DoctrineOrmOrderedRowLock implements OrderedRowLockInterface
{
    /** Ids per locking statement: far below every platform's parameter limit. */
    private const CHUNK = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function transactional(Closure $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->getTransactionNestingLevel() > 0) {
            return $work();
        }
        $connection->beginTransaction();
        try {
            $result = $work();
            $connection->commit();
        } catch (Throwable $failure) {
            TransactionRollback::toLevel($connection, 0);
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            // Audit SF-L5: a MySQL deadlock inside the flush's savepoint surfaces as 1305 (the savepoint is gone), with the
            // 1213 only in the chain; the host's retry looks for the deadlock itself.
            throw self::lockConflictIn($failure) ?? $failure;
        }

        return $result;
    }

    public function lockColumns(array $columnIds): void
    {
        $this->lock(BoardColumn::class, $columnIds);
    }

    public function lockTasks(array $taskIds): void
    {
        $this->lock(Task::class, $taskIds);
    }

    public function lockedColumnTasks(string $boardId, ?string $columnId, array $alsoTaskIds = [], bool $withArchived = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id', 't.position AS position', 'IDENTITY(t.column) AS col', 't.revision AS revision', 't.archivedAt AS archivedAt')
            ->from(Task::class, 't')
            ->orderBy('t.id', 'ASC');
        if (!$withArchived) {
            $qb->andWhere('t.archivedAt IS NULL');
        }
        // A column belongs to one board: its id alone, so the locking read walks the column's index, not the whole board.
        if ($columnId === null) {
            $qb->andWhere('IDENTITY(t.board) = :board')->andWhere('t.column IS NULL')->setParameter('board', $boardId);
        } else {
            $qb->andWhere('IDENTITY(t.column) = :column')->setParameter('column', $columnId);
        }
        /** @var list<array{id: int|string, position: int|string, col: int|string|null, revision: int|string, archivedAt: mixed}> $rows */
        $rows = $qb->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getArrayResult();

        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) $row['id']] = true;
        }
        $extra = array_values(array_filter(array_unique($alsoTaskIds), static fn (string $id): bool => !isset($seen[$id])));
        sort($extra, SORT_STRING);
        foreach (array_chunk($extra, self::CHUNK) as $chunk) {
            /** @var list<array{id: int|string, position: int|string, col: int|string|null, revision: int|string, archivedAt: mixed}> $more */
            $more = $this->entityManager->createQuery('SELECT t.id AS id, t.position AS position, IDENTITY(t.column) AS col, t.revision AS revision, t.archivedAt AS archivedAt FROM ' . Task::class . ' t WHERE t.id IN (:ids) ORDER BY t.id ASC')
                ->setParameter('ids', $chunk)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getArrayResult();
            $rows = [...$rows, ...$more];
        }

        $tasks = [];
        foreach ($rows as $row) {
            // The row is locked, so it cannot disappear between the locking read and this one.
            $task   = $this->entityManager->find(Task::class, (string) $row['id']) ?? throw new LogicException('A locked task row is gone.');
            $column = $row['col'] === null ? null : (string) $row['col'];
            // A copy loaded before a concurrent writer committed (or kept by a worker's identity map) starts from the row locked.
            if ($task->getPosition() !== (int) $row['position'] || $task->getColumn()?->getId() !== $column || $task->getRevision() !== (int) $row['revision'] || $task->isArchived() !== ($row['archivedAt'] !== null)) {
                // Audit SF-L2: a refresh would drop the caller's unsaved changes silently.
                if ($this->hasPendingChanges($task)) {
                    throw TaskTreeRefusedException::conflict();
                }
                $this->entityManager->refresh($task);
            }
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * Whether a managed task holds a change its unit of work has not written yet (a field or a to-one reference that
     * differs from what was loaded).
     */
    private function hasPendingChanges(Task $task): bool
    {
        $metadata = $this->entityManager->getClassMetadata(Task::class);
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($task);
        foreach ([...$metadata->getFieldNames(), ...$metadata->getAssociationNames()] as $name) {
            if (!array_key_exists($name, $original) || ($metadata->hasAssociation($name) && !$metadata->isSingleValuedAssociation($name))) {
                continue;
            }
            // An enum field reads as its value here and is kept as the case in the original data.
            $now = self::plain($metadata->getFieldValue($task, $name));
            $was = self::plain($original[$name]);
            // Values (dates) compare by value, references and scalars by identity.
            if (($now instanceof DateTimeInterface && $was instanceof DateTimeInterface) ? $now != $was : $now !== $was) {
                return true;
            }
        }

        return false;
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private static function lockConflictIn(Throwable $failure): ?Throwable
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof DeadlockException || $cause instanceof LockWaitTimeoutException) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * @param class-string $class
     * @param list<string> $ids
     */
    private function lock(string $class, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->entityManager->createQuery('SELECT e.id FROM ' . $class . ' e WHERE e.id IN (:ids) ORDER BY e.id ASC')
                ->setParameter('ids', $chunk)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleColumnResult();
        }
    }
}
