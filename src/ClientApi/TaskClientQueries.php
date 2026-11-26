<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Api\Doctrine\JsonTextFunction;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_int;
use function is_string;
use function json_encode;
use function mb_strtolower;
use function strtr;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 3.4.0 (client API v1): the client API's own queries — a person's assigned and reviewed tasks (My tasks), one
 * board's cards in a stable order with a keyset cursor, and the tasks changed since a cursor (polling). Every query is
 * bounded by the boards the caller already reached (the context's `where` and the person's board access); the caller
 * then keeps only the tasks the person may open (`canSeeTask()`) before it shows, counts or pages anything. Reads only,
 * no lock. Stateless (worker mode).
 *
 * @internal
 */
final readonly class TaskClientQueries
{
    /** Most open assignments read for My tasks (buckets and counts are computed over them). */
    public const MY_TASKS_SCAN = 2000;

    /** The sorts of a board's list (`sort=`), each a keyset over these expressions, the task id last. */
    public const SORTS = ['position', 'due', 'priority', 'updated', 'number'];

    /** A far date for "no due date" in a sort (after every real one). */
    private const NO_DATE = '9999-12-31 23:59:59';

    private const PRIORITY_RANK = "CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END";

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardUsers $users = new TaskBoardUsers(),
    ) {
    }

    /**
     * The ids of the open (not completed, not archived) tasks of the boards where the user is an assignee or a reviewer,
     * at most {@see self::MY_TASKS_SCAN}. The caller loads them, checks the membership exactly and the column type.
     *
     * @param list<string> $boardIds
     *
     * @return list<string>
     */
    public function myTaskIds(array $boardIds, object $user): array
    {
        if ($boardIds === []) {
            return [];
        }
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(Task::class, 't')
            ->where('t.board IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.completedAt IS NULL')
            ->andWhere('EXISTS (SELECT mine.id FROM ' . TaskMember::class . ' mine WHERE mine.task = t AND mine.memberRole IN (:roles) AND mine.user = :me)')
            ->setParameter('boards', $boardIds)
            ->setParameter('roles', [TaskMemberRole::Assignee, TaskMemberRole::Reviewer])
            ->setParameter('me', $this->users->parameter($user))
            ->orderBy('t.id', 'ASC')
            ->getQuery()->setMaxResults(self::MY_TASKS_SCAN)->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * One page of a board's tasks in a stable order: the ids and each row's cursor, at most `$limit + 1` rows (one more
     * tells there is a next page).
     *
     * @param array{column?: string|null, parent?: string|null, priorities?: list<TaskPriority>, tags?: list<string>, due?: string|null, me?: object|null, ids?: list<string>|null, today?: DateTimeImmutable} $filter
     * @param list<int|string>|null $after the cursor's values
     *
     * @return list<array{id: string, cursor: list<int|string>}>
     */
    public function boardPage(string $boardId, string $sort, array $filter, ?array $after, int $limit): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(Task::class, 't')
            ->leftJoin('t.column', 'col')
            ->where('t.board = :board')->setParameter('board', $boardId)
            ->andWhere('t.archivedAt IS NULL');
        $parent = $filter['parent'] ?? null;
        if (is_string($parent)) {
            $builder->andWhere('t.parent = :parent')->setParameter('parent', $parent);
        } else {
            $builder->andWhere('t.parent IS NULL');
        }
        $column = $filter['column'] ?? null;
        if (is_string($column)) {
            $builder->andWhere('t.column = :column')->setParameter('column', $column);
        }
        if (($filter['priorities'] ?? []) !== []) {
            $builder->andWhere('t.priority IN (:priorities)')->setParameter('priorities', $filter['priorities']);
        }
        $this->tags($builder, $filter['tags'] ?? []);
        $this->due($builder, $filter['due'] ?? null, $filter['today'] ?? new DateTimeImmutable('today'));
        if (isset($filter['me'])) {
            $builder->andWhere('EXISTS (SELECT am.id FROM ' . TaskMember::class . ' am WHERE am.task = t AND am.memberRole = :assignee AND am.user = :me)')
                ->setParameter('assignee', TaskMemberRole::Assignee)
                ->setParameter('me', $this->users->parameter($filter['me']));
        }
        if (isset($filter['ids'])) {
            $builder->andWhere('t.id IN (:ids)')->setParameter('ids', $filter['ids'] === [] ? [''] : $filter['ids']);
        }
        $keys = $this->sortKeys($sort, is_string($column));
        foreach ($keys as $index => [$expression]) {
            $builder->addSelect($expression . ' AS k' . $index);
        }
        $this->keyset($builder, $keys, $after);
        foreach ($keys as $index => [, $direction]) {
            $builder->addOrderBy('k' . $index, $direction);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->getQuery()->setMaxResults($limit + 1)->getArrayResult();

        return array_map(static function (array $row) use ($keys): array {
            $cursor = [];
            foreach (array_keys($keys) as $index) {
                $cursor[] = self::value($row['k' . $index] ?? null);
            }

            return ['id' => (string) $row['id'], 'cursor' => $cursor];
        }, $rows);
    }

    /**
     * Comments per task (deleted ones left out), in one query.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, int>
     */
    public function commentCounts(array $taskIds): array
    {
        /** @var list<array{task: string, comments: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.task) AS task', 'COUNT(c.id) AS comments')
            ->from(TaskComment::class, 'c')
            ->where('c.task IN (:tasks)')->setParameter('tasks', $taskIds)
            ->andWhere('c.deletedAt IS NULL')
            ->groupBy('c.task')
            ->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['task']] = (int) $row['comments'];
        }

        return $counts;
    }

    /**
     * The ids of the tasks of a column (not archived, under the same parent), by position then id, without `$except`:
     * where a move puts a task before or after another one.
     *
     * @return list<string>
     */
    public function columnTaskIds(string $boardId, string $columnId, ?string $parentId, string $except): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(Task::class, 't')
            ->where('t.board = :board')->setParameter('board', $boardId)
            ->andWhere('t.column = :column')->setParameter('column', $columnId)
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.id <> :except')->setParameter('except', $except)
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($parentId === null) {
            $builder->andWhere('t.parent IS NULL');
        } else {
            $builder->andWhere('t.parent = :parent')->setParameter('parent', $parentId);
        }
        /** @var list<array{id: string}> $rows */
        $rows = $builder->getQuery()->setMaxResults(10_000)->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * The tasks of the boards changed after the cursor (`updated_at`, id), oldest first, at most `$limit + 1` rows.
     * Archived tasks are changes too.
     *
     * @param list<string> $boardIds
     * @param array{0: string, 1: string}|null $after
     *
     * @return list<array{id: string, boardId: string, revision: int, updatedAt: string}>
     */
    public function changes(array $boardIds, ?array $after, int $limit): array
    {
        if ($boardIds === []) {
            return [];
        }
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id', 'IDENTITY(t.board) AS boardId', 't.revision AS revision', 't.updatedAt AS updatedAt')
            ->from(Task::class, 't')
            ->where('t.board IN (:boards)')->setParameter('boards', $boardIds)
            ->orderBy('t.updatedAt', 'ASC')->addOrderBy('t.id', 'ASC');
        if ($after !== null) {
            $builder->andWhere('t.updatedAt > :at OR (t.updatedAt = :at AND t.id > :id)')
                ->setParameter('at', new DateTimeImmutable($after[0]), 'datetime_immutable')
                ->setParameter('id', $after[1]);
        }
        /** @var list<array{id: string, boardId: string, revision: int, updatedAt: DateTimeInterface}> $rows */
        $rows = $builder->getQuery()->setMaxResults($limit + 1)->getArrayResult();

        return array_values(array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'boardId' => (string) $row['boardId'], 'revision' => (int) $row['revision'], 'updatedAt' => $row['updatedAt']->format('Y-m-d H:i:s')], $rows));
    }

    /**
     * The sort expressions and directions; the task id is always last (a stable order).
     *
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    private function sortKeys(string $sort, bool $oneColumn): array
    {
        return match ($sort) {
            'due'      => [["COALESCE(t.dueAt, '" . self::NO_DATE . "')", 'ASC'], ['t.id', 'ASC']],
            'priority' => [[self::PRIORITY_RANK, 'ASC'], ['t.id', 'ASC']],
            'updated'  => [['t.updatedAt', 'DESC'], ['t.id', 'DESC']],
            'number'   => [['COALESCE(t.number, 0)', 'ASC'], ['t.id', 'ASC']],
            default    => $oneColumn ? [['t.position', 'ASC'], ['t.id', 'ASC']] : [['COALESCE(col.position, -1)', 'ASC'], ['t.position', 'ASC'], ['t.id', 'ASC']],
        };
    }

    /**
     * The rows after the cursor: (a > x) OR (a = x AND b > y) OR …, each comparison in its key's direction.
     *
     * @param list<array{0: string, 1: 'ASC'|'DESC'}> $keys
     * @param list<int|string>|null $after
     */
    private function keyset(QueryBuilder $builder, array $keys, ?array $after): void
    {
        if ($after === null || count($after) !== count($keys)) {
            return;
        }
        $any = [];
        foreach ($keys as $index => [$expression, $direction]) {
            $all = [];
            foreach (array_slice($keys, 0, $index) as $equal => [$previous]) {
                $all[] = $previous . ' = :after' . $equal;
            }
            $all[] = $expression . ($direction === 'ASC' ? ' > ' : ' < ') . ':after' . $index;
            $any[] = '(' . implode(' AND ', $all) . ')';
        }
        $builder->andWhere(implode(' OR ', $any));
        foreach ($after as $index => $value) {
            $builder->setParameter('after' . $index, $value);
        }
    }

    /**
     * Tags are stored as a JSON list of strings: a quoted name matches one element exactly (case aside), as the Task API.
     *
     * @param list<string> $tags
     */
    private function tags(QueryBuilder $builder, array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $flags    = $platform instanceof AbstractMySQLPlatform && !$platform instanceof MariaDBPlatform ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0;
        $any      = [];
        foreach (array_values($tags) as $index => $tag) {
            $any[] = 'LOWER(' . JsonTextFunction::NAME . "(t.tags)) LIKE :tag{$index} ESCAPE '!'";
            $builder->setParameter('tag' . $index, '%' . strtr(mb_strtolower((string) json_encode($tag, $flags)), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        }
        $builder->andWhere(implode(' OR ', $any));
    }

    /** `due` = `overdue` (before today), `today`, `week` (today and the next 6 days) or `none`, in UTC days. */
    private function due(QueryBuilder $builder, ?string $due, DateTimeImmutable $today): void
    {
        $today = $today->setTime(0, 0);
        match ($due) {
            'overdue' => $builder->andWhere('t.dueAt < :dueFrom')->setParameter('dueFrom', $today, 'datetime_immutable'),
            'today'   => $builder->andWhere('t.dueAt >= :dueFrom AND t.dueAt < :dueTo')->setParameter('dueFrom', $today, 'datetime_immutable')->setParameter('dueTo', $today->modify('+1 day'), 'datetime_immutable'),
            'week'    => $builder->andWhere('t.dueAt >= :dueFrom AND t.dueAt < :dueTo')->setParameter('dueFrom', $today, 'datetime_immutable')->setParameter('dueTo', $today->modify('+7 days'), 'datetime_immutable'),
            'none'    => $builder->andWhere('t.dueAt IS NULL'),
            default   => null,
        };
    }

    /** A sort value as the cursor keeps it: integers as they are, dates as `Y-m-d H:i:s`, the rest as strings. */
    private static function value(mixed $value): int|string
    {
        return match (true) {
            is_int($value)                      => $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default                             => (string) $value,
        };
    }
}
