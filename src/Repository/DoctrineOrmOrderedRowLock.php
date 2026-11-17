<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Closure;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Service\OrderedRowLockInterface;
use Throwable;

use function array_chunk;
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

            throw $failure;
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
