<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use SortDirection;

use function sprintf;

/**
 * Since 2.1.0 (spec 214, FR-007): numbers the tasks that have none, per scope in order of `created_at`, then `id`. Resumable:
 * each batch stands alone and a task numbered meanwhile is skipped (a gap, never a duplicate). No effect once none is left.
 * Plain SQL, so `updated_at`, the history and the events stay as they were.
 */
final readonly class TaskNumberBackfiller
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskNumberScope $scope,
        private TaskNumberReserver $reserver,
    ) {
    }

    /** How many tasks have no number. */
    public function remaining(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Task::class, 't')
            ->where('t.number IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Numbers up to `$limit` tasks; returns how many it numbered (0: nothing is left). */
    public function runBatch(int $limit): int
    {
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()
            ->select('t', 'b')
            ->from(Task::class, 't')
            ->innerJoin('t.board', 'b')
            ->where('t.number IS NULL')
            ->orderBy('t.createdAt', SortDirection::Ascending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var list<array{id: string, scope: string}> $rows */
        $rows = [];
        foreach ($tasks as $task) {
            $key          = $this->scope->keyOf($task->getBoard());
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $rows[]       = ['id' => $task->getId(), 'scope' => $key];
        }
        if ($rows === []) {
            return 0;
        }

        $connection = $this->entityManager->getConnection();
        $next       = $this->reserver->reserve($connection, $counts);
        $table      = $this->entityManager->getClassMetadata(Task::class)->getTableName();

        // @igor-ignore - A static closure run at once by transactional(): nothing is kept in the service.
        $done = $connection->transactional(static function (Connection $db) use ($rows, $next, $table): int {
            $done = 0;
            foreach ($rows as $row) {
                $key    = $row['scope'];
                $number = $next[$key]++;
                if ($db->executeStatement(sprintf('UPDATE %s SET number = ? WHERE id = ? AND number IS NULL', $table), [$number, $row['id']]) === 1) {
                    $db->insert(TaskNumberEntry::TABLE, ['scope_key' => $key, 'number' => $number, 'task_id' => $row['id']]);
                    ++$done;
                }
            }

            return $done;
        });

        // The tasks loaded above are stale now, and a long run must not keep them.
        $this->entityManager->clear();

        return $done;
    }
}
