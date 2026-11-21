<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Dto\TaskCriteria;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskSort;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Nowo\TaskBoardBundle\View\BoardPage;
use Nowo\TaskBoardBundle\View\BoardView;
use Nowo\TaskBoardBundle\View\TaskPage;
use Nowo\TaskBoardBundle\View\TaskView;
use Nowo\TaskBoardBundle\View\Views;

use function array_fill_keys;
use function array_filter;
use function array_key_last;
use function array_values;
use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;
use function strcmp;
use function uksort;

/**
 * Since 2.29.0 (DDD-host checklist B7): the bundle's {@see TaskBoardQueryInterface}. Reads only, on the bundle's entity
 * manager; stateless (FrankenPHP worker mode, kernel reset off: nothing is kept between calls, and the entities it loads
 * live in the manager the bundle drops at every unit-of-work boundary).
 */
final readonly class TaskBoardQuery implements TaskBoardQueryInterface
{
    /** Rows per batch of the tag histogram scan. */
    private const BATCH = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardUsers $users = new TaskBoardUsers(),
    ) {
    }

    public function findBoards(BoardCriteria $criteria): BoardPage
    {
        if ($criteria->tenantKeys === []) {
            return new BoardPage([], 0, $criteria->offset, $criteria->limit);
        }
        /** @var list<TaskBoard> $boards */
        $boards = $this->boardScope($criteria->tenantKeys, $criteria->archived)
            ->select('b')
            ->orderBy('b.name', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->setFirstResult($criteria->offset)
            ->setMaxResults($criteria->limit)
            ->getQuery()
            ->getResult();
        $items = [];
        foreach ($boards as $board) {
            if (ExactKey::oneOf($board->getTenantKey(), $criteria->tenantKeys)) {
                $items[] = Views::board($board);
            }
        }
        /** @var list<array{tenantKey: mixed, n: mixed}> $rows */
        $rows = $this->boardScope($criteria->tenantKeys, $criteria->archived)
            ->select('b.tenantKey AS tenantKey', 'COUNT(b.id) AS n')
            // Per board, not per key: a folding collation would merge ACME's group into acme's.
            ->groupBy('b.id')
            ->addGroupBy('b.tenantKey')
            ->getQuery()
            ->getArrayResult();

        return new BoardPage($items, $this->exactSum($rows, 'tenantKey', $criteria->tenantKeys), $criteria->offset, $criteria->limit);
    }

    public function findBoardById(string $boardId): ?BoardView
    {
        $board = $boardId === '' ? null : $this->entityManager->find(TaskBoard::class, $boardId);

        return $board instanceof TaskBoard && $board->getId() === $boardId ? Views::board($board) : null;
    }

    public function countActiveBoards(array $tenantKeys): array
    {
        $keys = $this->keys($tenantKeys, BoardCriteria::MAX_LIMIT);
        if ($keys === []) {
            return [];
        }
        /** @var list<array{tenantKey: mixed, n: mixed}> $rows */
        $rows = $this->boardScope($keys, false)
            ->select('b.tenantKey AS tenantKey', 'COUNT(b.id) AS n')
            // Per board, not per key: a folding collation would merge ACME's group into acme's.
            ->groupBy('b.id')
            ->addGroupBy('b.tenantKey')
            ->getQuery()
            ->getArrayResult();

        return $this->perKey($rows, $keys);
    }

    public function findTasks(TaskCriteria $criteria): TaskPage
    {
        if ($criteria->isEmpty()) {
            return new TaskPage([], 0, $criteria->offset, $criteria->limit);
        }
        $builder = $this->taskScope($criteria)->select('t', 'b');
        match ($criteria->sort) {
            // @igor-ignore - A query builder created in this call; nothing is kept on the service.
            TaskSort::CreatedDesc => $builder->orderBy('t.createdAt', 'DESC')->addOrderBy('t.id', 'DESC'),
            // @igor-ignore - A query builder created in this call; nothing is kept on the service.
            TaskSort::CreatedAsc => $builder->orderBy('t.createdAt', 'ASC')->addOrderBy('t.id', 'ASC'),
            // @igor-ignore - A query builder created in this call; nothing is kept on the service.
            TaskSort::UpdatedDesc => $builder->orderBy('t.updatedAt', 'DESC')->addOrderBy('t.id', 'DESC'),
            // Tasks without a due date last on every platform.
            // @igor-ignore - A query builder created in this call; nothing is kept on the service.
            TaskSort::DueAsc => $builder->addSelect('CASE WHEN t.dueAt IS NULL THEN 1 ELSE 0 END AS HIDDEN noDue')->orderBy('noDue', 'ASC')->addOrderBy('t.dueAt', 'ASC')->addOrderBy('t.id', 'ASC'),
        };
        /** @var list<Task> $tasks */
        // @igor-ignore - A query builder created in this call; nothing is kept on the service.
        $tasks = $builder->setFirstResult($criteria->offset)->setMaxResults($criteria->limit)->getQuery()->getResult();
        $items = [];
        foreach ($tasks as $task) {
            if ($this->matchesExactly($task, $criteria)) {
                $items[] = Views::task($task);
            }
        }

        return new TaskPage($items, $this->countTasks($criteria), $criteria->offset, $criteria->limit);
    }

    public function countTasks(TaskCriteria $criteria): int
    {
        if ($criteria->isEmpty()) {
            return 0;
        }
        $byTenant = $criteria->tenantKeys !== [];
        $creator  = $criteria->creatorId !== null ? $this->users->identity('t.creator') : null;
        $builder  = $this->taskScope($criteria)
            ->select(($byTenant ? 'b.tenantKey' : 'b.id') . ' AS scopeKey', 'COUNT(t.id) AS n')
            // Per board, not per key: a folding collation would merge ACME's group into acme's.
            ->groupBy('b.id')
            ->addGroupBy('b.tenantKey');
        if ($creator !== null) {
            // @igor-ignore - A query builder created in this call; nothing is kept on the service.
            $builder->addSelect($creator . ' AS creatorKey')->addGroupBy('creatorKey');
        }
        /** @var list<array{scopeKey: mixed, n: mixed, creatorKey?: mixed}> $rows */
        $rows  = $builder->getQuery()->getArrayResult();
        $total = 0;
        foreach ($rows as $row) {
            if (!ExactKey::oneOf($row['scopeKey'], $byTenant ? $criteria->tenantKeys : $criteria->boardIds)) {
                continue;
            }
            if ($creator !== null && !$this->sameUserId($row['creatorKey'] ?? null, (string) $criteria->creatorId)) {
                continue;
            }
            $total += (int) $row['n'];
        }

        return $total;
    }

    public function findTaskById(string $taskId): ?TaskView
    {
        $task = $taskId === '' ? null : $this->entityManager->find(Task::class, $taskId);

        return $task instanceof Task && $task->getId() === $taskId ? Views::task($task) : null;
    }

    public function findTasksByIds(array $taskIds): array
    {
        if (count($taskIds) > TaskCriteria::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('TaskBoard: at most %d task ids per call.', TaskCriteria::MAX_LIMIT));
        }
        $ids = array_values(array_filter($taskIds, static fn (string $id): bool => $id !== ''));
        if ($ids === []) {
            return [];
        }
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()
            ->select('t', 'b')
            ->from(Task::class, 't')
            ->join('t.board', 'b')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->getId()] = $task;
        }
        $views = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $views[] = Views::task($byId[$id]);
            }
        }

        return $views;
    }

    public function countOpenTasks(array $tenantKeys): array
    {
        $keys = $this->keys($tenantKeys, TaskCriteria::MAX_LIMIT);
        if ($keys === []) {
            return [];
        }
        /** @var list<array{tenantKey: mixed, n: mixed}> $rows */
        $rows = $this->boardScope($keys, false)
            ->join(Task::class, 't', 'WITH', 't.board = b')
            ->select('b.tenantKey AS tenantKey', 'COUNT(t.id) AS n')
            ->andWhere('t.completedAt IS NULL')
            ->andWhere('t.archivedAt IS NULL')
            // Per board, not per key: a folding collation would merge ACME's group into acme's.
            ->groupBy('b.id')
            ->addGroupBy('b.tenantKey')
            ->getQuery()
            ->getArrayResult();

        return $this->perKey($rows, $keys);
    }

    public function tagCounts(array $tenantKeys, bool $openOnly = true): array
    {
        $keys = $this->keys($tenantKeys, TaskCriteria::MAX_LIMIT);
        if ($keys === []) {
            return [];
        }
        $counts = [];
        $after  = null;
        do {
            // Keyset batches by task id: memory stays bounded whatever the number of tasks.
            $builder = $this->boardScope($keys, false)
                ->join(Task::class, 't', 'WITH', 't.board = b')
                ->select('t.id AS id', 't.tags AS tags', 'b.tenantKey AS tenantKey')
                ->andWhere('t.archivedAt IS NULL')
                ->orderBy('t.id', 'ASC')
                ->setMaxResults(self::BATCH);
            if ($openOnly) {
                $builder->andWhere('t.completedAt IS NULL');
            }
            if ($after !== null) {
                // @igor-ignore - A query builder created in this call; nothing is kept on the service.
                $builder->andWhere('t.id > :after')->setParameter('after', $after);
            }
            /** @var list<array{id: mixed, tags: mixed, tenantKey: mixed}> $rows */
            $rows = $builder->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                if (!ExactKey::oneOf($row['tenantKey'], $keys) || !is_array($row['tags'])) {
                    continue;
                }
                $seen = [];
                foreach ($row['tags'] as $tag) {
                    if (is_string($tag) && $tag !== '' && !isset($seen[$tag])) {
                        $seen[$tag]   = true;
                        $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                    }
                }
            }
            $last  = $rows === [] ? null : $rows[array_key_last($rows)]['id'];
            $after = is_scalar($last) ? (string) $last : null;
        } while (count($rows) === self::BATCH);

        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b] ?: strcmp($a, $b));

        return $counts;
    }

    /**
     * Boards of these tenants (candidates: PHP keeps the exact keys), active (false), archived (true) or both (null).
     *
     * @param list<string> $keys
     */
    private function boardScope(array $keys, ?bool $archived): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(TaskBoard::class, 'b')
            ->where('b.tenantKey IN (:keys)')
            ->setParameter('keys', $keys);
        if ($archived === false) {
            $builder->andWhere('b.archivedAt IS NULL');
        } elseif ($archived === true) {
            $builder->andWhere('b.archivedAt IS NOT NULL');
        }

        return $builder;
    }

    private function taskScope(TaskCriteria $criteria): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(Task::class, 't')
            ->join('t.board', 'b');
        if ($criteria->tenantKeys !== []) {
            $builder->where('b.tenantKey IN (:keys)')->setParameter('keys', $criteria->tenantKeys);
        } else {
            $builder->where('b.id IN (:boards)')->setParameter('boards', $criteria->boardIds);
        }
        if ($criteria->completed !== null) {
            $builder->andWhere($criteria->completed ? 't.completedAt IS NOT NULL' : 't.completedAt IS NULL');
        }
        if ($criteria->archived !== null) {
            $builder->andWhere($criteria->archived ? 't.archivedAt IS NOT NULL' : 't.archivedAt IS NULL');
        }
        if ($criteria->dueFrom instanceof DateTimeImmutable || $criteria->dueTo instanceof DateTimeImmutable) {
            $builder->andWhere('t.dueAt IS NOT NULL');
        }
        if ($criteria->dueFrom instanceof DateTimeImmutable) {
            $builder->andWhere('t.dueAt >= :dueFrom')->setParameter('dueFrom', $criteria->dueFrom, 'datetime_immutable');
        }
        if ($criteria->dueTo instanceof DateTimeImmutable) {
            $builder->andWhere('t.dueAt <= :dueTo')->setParameter('dueTo', $criteria->dueTo, 'datetime_immutable');
        }
        if ($criteria->columnId !== null) {
            $builder->andWhere('IDENTITY(t.column) = :column')->setParameter('column', $criteria->columnId);
        }
        if ($criteria->creatorId !== null) {
            $builder->andWhere($this->users->identity('t.creator') . ' = :creator')->setParameter('creator', $criteria->creatorId);
        }
        if ($criteria->topLevelOnly) {
            $builder->andWhere('t.parent IS NULL');
        }

        return $builder;
    }

    /** The PHP side of the exact match of a loaded task (the database may fold the keys it compared). */
    private function matchesExactly(Task $task, TaskCriteria $criteria): bool
    {
        $board = $task->getBoard();
        if ($criteria->tenantKeys !== [] ? !ExactKey::oneOf($board->getTenantKey(), $criteria->tenantKeys) : !ExactKey::oneOf($board->getId(), $criteria->boardIds)) {
            return false;
        }

        return $criteria->creatorId === null || ActorId::of($task->getCreator()) === $criteria->creatorId;
    }

    private function sameUserId(mixed $stored, string $id): bool
    {
        $stored = $stored instanceof TaskBoardUserRef ? $stored->id : $stored;

        return is_scalar($stored) && (string) $stored === $id;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function keys(array $keys, int $max): array
    {
        if (count($keys) > $max) {
            throw new InvalidArgumentException(sprintf('TaskBoard: at most %d tenant keys per call.', $max));
        }

        return BoardCriteria::forTenants($keys)->tenantKeys;
    }

    /**
     * @param list<array{tenantKey: mixed, n: mixed}> $rows
     * @param list<string> $keys
     *
     * @return array<string, int>
     */
    private function perKey(array $rows, array $keys): array
    {
        $counts = array_fill_keys($keys, 0);
        foreach ($rows as $row) {
            $key = $row['tenantKey'];
            if (is_string($key) && ExactKey::oneOf($key, $keys)) {
                $counts[$key] += (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keys
     */
    private function exactSum(array $rows, string $column, array $keys): int
    {
        $total = 0;
        foreach ($rows as $row) {
            if (ExactKey::oneOf($row[$column] ?? null, $keys)) {
                $total += (int) ($row['n'] ?? 0);
            }
        }

        return $total;
    }
}
