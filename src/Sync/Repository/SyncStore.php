<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncAttachment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use SortDirection;

use function array_chunk;
use function array_flip;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function max;

/**
 * Since 1.23: the queries of the provider sync (connection, run lock, items, history).
 */
final readonly class SyncStore
{
    private const CHUNK = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function findConnection(TaskBoard $board): ?TaskBoardConnection
    {
        return $this->entityManager->getRepository(TaskBoardConnection::class)->findOneBy(['board' => $board]);
    }

    /**
     * Takes the run lock of a connection (one run per board) when it is free or stale (no heartbeat since
     * `$staleBefore`). Since audit EG-L1 never when the same run holds it: a second slice of a running run is Busy.
     */
    public function acquireLock(TaskBoardConnection $connection, string $runId, DateTimeImmutable $now, DateTimeImmutable $staleBefore): bool
    {
        $updated = $this->entityManager->createQuery(
            'UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = :run, c.heartbeatAt = :now
             WHERE c.id = :id AND (c.runningRunId IS NULL OR c.heartbeatAt IS NULL OR c.heartbeatAt < :stale)',
        )
            ->setParameter('run', $runId)
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('stale', $staleBefore, 'datetime_immutable')
            ->setParameter('id', $connection->getId())
            ->execute();

        return (int) $updated === 1;
    }

    public function heartbeat(string $connectionId, string $runId, DateTimeImmutable $now): void
    {
        $this->entityManager->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.heartbeatAt = :now WHERE c.id = :id AND c.runningRunId = :run')
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('id', $connectionId)
            ->setParameter('run', $runId)
            ->execute();
    }

    public function releaseLock(string $connectionId, string $runId): void
    {
        $this->entityManager->createQuery('UPDATE ' . TaskBoardConnection::class . ' c SET c.runningRunId = NULL, c.heartbeatAt = NULL WHERE c.id = :id AND c.runningRunId = :run')
            ->setParameter('id', $connectionId)
            ->setParameter('run', $runId)
            ->execute();
        // The UPDATE bypasses the identity map: a connection already loaded shows the free lock too.
        $loadedConnections = $this->entityManager->isOpen() ? $this->entityManager->getUnitOfWork()->getIdentityMap()[TaskBoardConnection::class] ?? [] : [];
        foreach ($loadedConnections as $loaded) {
            if ($loaded instanceof TaskBoardConnection && $loaded->getId() === $connectionId) {
                $this->entityManager->refresh($loaded);
            }
        }
    }

    /**
     * @param list<string> $externalIds
     *
     * @return array<string, TaskSyncItem>
     */
    public function itemsByExternalIds(TaskBoardConnection $connection, array $externalIds): array
    {
        $items = [];
        foreach (array_chunk(array_values($externalIds), self::CHUNK) as $chunk) {
            /** @var list<TaskSyncItem> $found */
            $found = $this->entityManager->createQueryBuilder()
                ->select('i', 't')
                ->from(TaskSyncItem::class, 'i')
                ->leftJoin('i.task', 't')
                ->where('i.boardId = :board')
                ->andWhere('i.externalId IN (:ids)')
                ->setParameter('board', $connection->getBoard()->getId())
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->getResult();
            foreach ($found as $item) {
                $items[$item->getExternalId()] = $item;
            }
        }

        return $items;
    }

    /**
     * The items of the connection that point to a task, as [item id, external id, task archived, archive reason,
     * remote archived] — scalars only, for the deletions phase.
     *
     * @return list<array{id: string, externalId: string, archived: bool, reason: string|null, remoteArchived: bool}>
     */
    public function linkedItems(TaskBoardConnection $connection): array
    {
        /** @var list<array{id: string, externalId: string, archivedAt: DateTimeImmutable|null, reason: string|null, remoteArchivedAt: DateTimeImmutable|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i.id AS id', 'i.externalId AS externalId', 't.archivedAt AS archivedAt', 't.archiveReason AS reason', 'i.remoteArchivedAt AS remoteArchivedAt')
            ->from(TaskSyncItem::class, 'i')
            ->innerJoin('i.task', 't')
            ->where('i.connection = :connection')
            ->setParameter('connection', $connection)
            ->getQuery()
            ->getArrayResult();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'             => $row['id'],
                'externalId'     => $row['externalId'],
                'archived'       => $row['archivedAt'] !== null,
                'reason'         => $row['reason'],
                'remoteArchived' => $row['remoteArchivedAt'] !== null,
            ];
        }

        return $items;
    }

    /**
     * Since 1.25: the board's tasks (archived ones excluded) per column id ("" = no column), one grouped query.
     *
     * @return array<string, int>
     */
    public function tasksPerColumn(TaskBoard $board): array
    {
        /** @var list<array{column: string|null, n: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.column) AS column', 'COUNT(t.id) AS n')
            ->from(Task::class, 't')
            ->where('t.board = :board')->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board)
            ->groupBy('t.column')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['column']] = (int) $row['n'];
        }

        return $counts;
    }

    public function findItem(string $id): ?TaskSyncItem
    {
        return $this->entityManager->find(TaskSyncItem::class, $id);
    }

    /**
     * @return array<string, TaskSyncComment>
     */
    public function commentsOf(TaskSyncItem $item): array
    {
        $comments = [];
        foreach ($this->entityManager->getRepository(TaskSyncComment::class)->findBy(['item' => $item]) as $comment) {
            $comments[$comment->getRemoteId()] = $comment;
        }

        return $comments;
    }

    /**
     * @return array<string, TaskSyncAttachment>
     */
    public function attachmentsOf(Task $task): array
    {
        $files = [];
        foreach ($this->entityManager->getRepository(TaskSyncAttachment::class)->findBy(['task' => $task]) as $file) {
            $files[$file->getRemoteId()] = $file;
        }

        return $files;
    }

    public function findRun(string $id): ?TaskSyncRun
    {
        return $this->entityManager->find(TaskSyncRun::class, $id);
    }

    public function isCancelRequested(string $runId): bool
    {
        $value = $this->entityManager->createQueryBuilder()
            ->select('r.cancelRequested')
            ->from(TaskSyncRun::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $runId)
            ->getQuery()
            ->getOneOrNullResult();

        return ($value['cancelRequested'] ?? false) === true;
    }

    /**
     * @return list<TaskSyncRun>
     */
    public function latestRuns(TaskBoardConnection $connection, int $limit): array
    {
        /** @var list<TaskSyncRun> $runs */
        $runs = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(TaskSyncRun::class, 'r')
            ->where('r.connection = :connection')
            ->setParameter('connection', $connection)
            ->orderBy('r.runOrder', SortDirection::Descending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /** Keeps the `$keep` most recent runs of the connection. */
    public function prune(TaskBoardConnection $connection, int $keep): void
    {
        $runs = $this->latestRuns($connection, $keep + 200);
        if (count($runs) <= $keep) {
            return;
        }
        foreach (array_slice($runs, $keep) as $run) {
            $this->entityManager->remove($run);
        }
        $this->entityManager->flush();
    }

    /** Removes the connection and its sync state (runs, items, comment and file maps); tasks are kept. */
    public function deleteConnection(TaskBoardConnection $connection): void
    {
        $items = $this->entityManager->createQueryBuilder()->select('i.id')->from(TaskSyncItem::class, 'i')->where('i.connection = :c')->setParameter('c', $connection)->getQuery()->getSingleColumnResult();
        foreach (array_chunk($items, self::CHUNK) as $chunk) {
            $this->entityManager->createQuery('DELETE FROM ' . TaskSyncComment::class . ' x WHERE x.item IN (:items)')->setParameter('items', $chunk)->execute();
            $this->entityManager->createQuery('DELETE FROM ' . TaskSyncAttachment::class . ' x WHERE x.item IN (:items)')->setParameter('items', $chunk)->execute();
        }
        $this->entityManager->createQuery('DELETE FROM ' . TaskSyncItem::class . ' x WHERE x.connection = :c')->setParameter('c', $connection)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . TaskSyncRun::class . ' x WHERE x.connection = :c')->setParameter('c', $connection)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . TaskBoardConnection::class . ' x WHERE x.id = :id')->setParameter('id', $connection->getId())->execute();
        // Audit FP-L5: the DQL deletes bypass the identity map. Rows of this connection that are still loaded would make
        // the next flush (the directory prune) fail after the deletes: they leave the entity manager too.
        $this->forgetLoaded($connection, $items);
        $this->entityManager->detach($connection);
    }

    /**
     * @param array<mixed> $itemIds the ids of the connection's items (already deleted)
     */
    private function forgetLoaded(TaskBoardConnection $connection, array $itemIds): void
    {
        $items = array_flip(array_map(strval(...), $itemIds));
        $map   = $this->entityManager->getUnitOfWork()->getIdentityMap();
        foreach ([TaskSyncComment::class, TaskSyncAttachment::class, TaskSyncMember::class] as $class) {
            foreach ($map[$class] ?? [] as $row) {
                // getId() on the item reference never loads it (the row is gone).
                if (($row instanceof TaskSyncComment || $row instanceof TaskSyncAttachment || $row instanceof TaskSyncMember) && isset($items[$row->getItem()->getId()])) {
                    $this->entityManager->detach($row);
                }
            }
        }
        foreach ([TaskSyncItem::class, TaskSyncRun::class] as $class) {
            foreach ($map[$class] ?? [] as $row) {
                if (($row instanceof TaskSyncItem || $row instanceof TaskSyncRun) && $row->getConnection()->getId() === $connection->getId()) {
                    $this->entityManager->detach($row);
                }
            }
        }
    }
}
