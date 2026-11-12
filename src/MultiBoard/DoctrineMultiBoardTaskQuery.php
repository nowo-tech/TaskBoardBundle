<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Support\Like;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use SortDirection;

use function array_chunk;
use function array_keys;
use function array_map;
use function array_values;
use function ctype_digit;
use function ltrim;
use function mb_strtolower;
use function strlen;
use function strval;

/**
 * Since 2.13.0: the queries of the multi-board list. Every task query is ONE bounded query over an IN-list of board ids
 * the caller already checked (no per-board or per-task query): plain values, no entity hydration, so a page or an export
 * batch never fills the identity map with tasks. Indexes used: `task_board_tasks_board_archived_idx` (board_id,
 * archived_at) and `task_board_tasks_board_open_due_idx` (board_id, completed_at, due_at); the assignees come from the
 * member table's task foreign-key index.
 *
 * It checks nothing: {@see MultiBoardTaskList} passes only boards the viewer may read.
 *
 * @internal
 */
final readonly class DoctrineMultiBoardTaskQuery
{
    /** Ids per IN-list of the member and user lookups. */
    private const ID_CHUNK = 500;

    /** Sort key => ORDER BY expression (hidden select aliases for the computed ones). */
    private const ORDER = [
        'due'      => ['dueMissing', 't.dueAt'],
        'priority' => ['priorityRank'],
        'title'    => ['t.title'],
        'board'    => ['b.name'],
        'column'   => ['c.name'],
        'number'   => ['t.number'],
        'created'  => ['t.createdAt'],
        'updated'  => ['t.updatedAt'],
    ];

    /**
     * @param class-string $userClass `user_class`
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $userClass,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $users = null,
    ) {
    }

    /**
     * The active boards among `$ids` (one query; `$ids` is never empty).
     *
     * @param list<string> $ids
     *
     * @return list<TaskBoard>
     */
    public function boards(array $ids): array
    {
        /** @var list<TaskBoard> $boards */
        $boards = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.id IN (:ids)')
            ->andWhere('b.archivedAt IS NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $boards;
    }

    /**
     * @param list<string> $boardIds
     */
    public function count(array $boardIds, MultiBoardTaskFilter $filter, DateTimeImmutable $now): int
    {
        return (int) $this->base($boardIds, $filter, $now)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * The matching tasks, sorted, as plain values (one query).
     *
     * @param list<string> $boardIds
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $boardIds, MultiBoardTaskFilter $filter, DateTimeImmutable $now, int $offset, int $limit): array
    {
        $qb = $this->base($boardIds, $filter, $now)
            ->select(
                't.id AS taskId',
                't.number AS number',
                't.title AS title',
                't.priority AS priority',
                't.startAt AS startAt',
                't.dueAt AS dueAt',
                't.completedAt AS completedAt',
                't.estimatedMinutes AS estimatedMinutes',
                't.totalTimeSeconds AS totalTimeSeconds',
                't.tags AS tags',
                'IDENTITY(t.parent) AS parentId',
                'b.id AS boardId',
                'c.name AS columnName',
                'CASE WHEN t.dueAt IS NULL THEN 1 ELSE 0 END AS HIDDEN dueMissing',
                "CASE WHEN t.priority = 'urgent' THEN 3 WHEN t.priority = 'high' THEN 2 WHEN t.priority = 'normal' THEN 1 ELSE 0 END AS HIDDEN priorityRank",
            )
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $direction = $filter->direction === 'desc' ? SortDirection::Descending : SortDirection::Ascending;
        $first     = true;
        foreach (self::ORDER[$filter->sort] ?? self::ORDER[MultiBoardTaskFilter::DEFAULT_SORT] as $expression) {
            // "No due date" sorts last in both directions.
            $expressionDirection = $expression === 'dueMissing' ? SortDirection::Ascending : $direction;
            // @igor-ignore - A query builder made for this call, not a shared service.
            $first ? $qb->orderBy($expression, $expressionDirection) : $qb->addOrderBy($expression, $expressionDirection);
            $first = false;
        }
        // @igor-ignore - A query builder made for this call, not a shared service.
        $qb->addOrderBy('t.id', SortDirection::Ascending);

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * The assignee ids of each task (one query per 500 tasks).
     *
     * @param list<string> $taskIds
     *
     * @return array<string, list<string>> task id => user ids
     */
    public function assigneeIds(array $taskIds): array
    {
        $assignees = [];
        foreach (array_chunk($taskIds, self::ID_CHUNK) as $chunk) {
            /** @var list<array{taskId: mixed, userId: mixed}> $members */
            $members = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(m.task) AS taskId', $this->userValue('m.user') . ' AS userId')
                ->from(TaskMember::class, 'm')
                ->where('IDENTITY(m.task) IN (:tasks)')
                ->andWhere('m.memberRole = :role')
                ->setParameter('tasks', $chunk)
                ->setParameter('role', TaskMemberRole::Assignee)
                ->orderBy('m.id', SortDirection::Ascending)
                ->getQuery()
                ->getArrayResult();
            foreach ($members as $member) {
                if ($member['userId'] !== null) {
                    $assignees[(string) $member['taskId']][] = (string) $member['userId'];
                }
            }
        }

        return $assignees;
    }

    /**
     * The labels of the users (one query per 500 users).
     *
     * @param list<string> $userIds
     *
     * @return array<string, string> user id => label
     */
    public function people(array $userIds): array
    {
        // Since 2.15.0 (database.user_reference: id): the host's directory names them (no user table to read).
        if ($this->users instanceof TaskBoardUsers && $this->users->idMode()) {
            $labels = [];
            foreach ($userIds as $id) {
                $user = $this->users->find($id);
                if ($user !== null) {
                    $labels[$id] = UserLabel::of($user);
                }
            }

            return $labels;
        }
        $field  = $this->entityManager->getClassMetadata($this->userClass)->getSingleIdentifierFieldName();
        $labels = [];
        foreach (array_chunk($userIds, self::ID_CHUNK) as $chunk) {
            /** @var list<object> $users */
            $users = $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from($this->userClass, 'u')
                ->where('u.' . $field . ' IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->getResult();
            foreach ($users as $user) {
                $id = $this->entityManager->getUnitOfWork()->getSingleIdentifierValue($user);
                if ($id !== null) {
                    $labels[(string) $id] = UserLabel::of($user);
                }
            }
        }

        return $labels;
    }

    /**
     * Every assignee of an active task of the boards (one query).
     *
     * @param list<string> $boardIds
     *
     * @return list<string> user ids
     */
    public function assigneesOf(array $boardIds): array
    {
        /** @var list<array{userId: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT ' . $this->userValue('m.user') . ' AS userId')
            ->from(TaskMember::class, 'm')
            ->innerJoin('m.task', 't')
            ->where('IDENTITY(t.board) IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('m.memberRole = :role')
            ->setParameter('boards', $boardIds)
            ->setParameter('role', TaskMemberRole::Assignee)
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            if ($row['userId'] !== null) {
                $ids[(string) $row['userId']] = true;
            }
        }

        // Numeric-string keys come back as integers: the ids stay strings.
        return array_map(strval(...), array_keys($ids));
    }

    /**
     * The distinct column names of the boards (one query).
     *
     * @param list<string> $boardIds
     *
     * @return list<string>
     */
    public function columnNames(array $boardIds): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT c.name AS name')
            ->from(BoardColumn::class, 'c')
            ->where('IDENTITY(c.board) IN (:boards)')
            ->setParameter('boards', $boardIds)
            ->orderBy('c.name', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[mb_strtolower($row['name'])] ??= $row['name'];
        }

        return array_values($names);
    }

    /**
     * The workload per assignee (one grouped query; `userId` null: unassigned). The status filter does not apply: open and
     * completed tasks are counted apart.
     *
     * @param list<string> $boardIds
     *
     * @return list<array{userId: mixed, openCount: mixed, overdueCount: mixed, doneCount: mixed, estimate: mixed, logged: mixed}>
     */
    public function workload(array $boardIds, MultiBoardTaskFilter $filter, DateTimeImmutable $now): array
    {
        $qb = $this->base($boardIds, $filter, $now, false)
            ->select(
                $this->userValue('wm.user') . ' AS userId',
                'SUM(CASE WHEN t.completedAt IS NULL THEN 1 ELSE 0 END) AS openCount',
                'SUM(CASE WHEN t.completedAt IS NULL AND t.dueAt < :workloadNow THEN 1 ELSE 0 END) AS overdueCount',
                'SUM(CASE WHEN t.completedAt IS NOT NULL THEN 1 ELSE 0 END) AS doneCount',
                'SUM(CASE WHEN t.completedAt IS NULL AND t.estimatedMinutes IS NOT NULL THEN t.estimatedMinutes ELSE 0 END) AS estimate',
                'SUM(t.totalTimeSeconds) AS logged',
            )
            ->leftJoin(TaskMember::class, 'wm', 'WITH', 'wm.task = t AND wm.memberRole = :workloadRole')
            ->setParameter('workloadRole', TaskMemberRole::Assignee)
            ->setParameter('workloadNow', $now, 'datetime_immutable')
            ->groupBy('wm.user');

        /** @var list<array{userId: mixed, openCount: mixed, overdueCount: mixed, doneCount: mixed, estimate: mixed, logged: mixed}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * The filtered tasks of the boards: active tasks of active boards, then each filter as one condition.
     *
     * @param list<string> $boardIds
     */
    private function base(array $boardIds, MultiBoardTaskFilter $filter, DateTimeImmutable $now, bool $withStatus = true): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Task::class, 't')
            ->innerJoin('t.board', 'b')
            ->leftJoin('t.column', 'c')
            ->where('IDENTITY(t.board) IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('b.archivedAt IS NULL')
            ->setParameter('boards', $boardIds);

        if ($withStatus && $filter->status === MultiBoardTaskFilter::STATUS_OPEN) {
            $qb->andWhere('t.completedAt IS NULL');
        } elseif ($withStatus && $filter->status === MultiBoardTaskFilter::STATUS_DONE) {
            $qb->andWhere('t.completedAt IS NOT NULL');
        }
        if ($filter->priority instanceof TaskPriority) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $filter->priority);
        }
        if ($filter->column !== null) {
            $qb->andWhere('LOWER(c.name) = :column')->setParameter('column', mb_strtolower($filter->column));
        }
        if ($filter->assigneeId === MultiBoardTaskFilter::UNASSIGNED) {
            $qb->andWhere('t.id NOT IN (SELECT IDENTITY(na.task) FROM ' . TaskMember::class . ' na WHERE na.memberRole = :assigneeRole)')
                ->setParameter('assigneeRole', TaskMemberRole::Assignee);
        } elseif ($filter->assigneeId !== null) {
            $qb->andWhere('t.id IN (SELECT IDENTITY(am.task) FROM ' . TaskMember::class . ' am WHERE am.memberRole = :assigneeRole AND ' . $this->userValue('am.user') . ' = :assignee)')
                ->setParameter('assigneeRole', TaskMemberRole::Assignee)
                ->setParameter('assignee', $filter->assigneeId);
        }
        if ($filter->dueFrom instanceof DateTimeImmutable) {
            $qb->andWhere('t.dueAt >= :dueFrom')->setParameter('dueFrom', $filter->dueFrom, 'datetime_immutable');
        }
        if ($filter->dueTo instanceof DateTimeImmutable) {
            $qb->andWhere('t.dueAt < :dueTo')->setParameter('dueTo', $filter->dueTo->add(new DateInterval('P1D')), 'datetime_immutable');
        }
        if ($filter->overdue) {
            $qb->andWhere('t.completedAt IS NULL')->andWhere('t.dueAt < :overdueNow')->setParameter('overdueNow', $now, 'datetime_immutable');
        }
        if ($filter->search !== null) {
            $number = ltrim($filter->search, '#');
            if ($number !== '' && strlen($number) <= 9 && ctype_digit($number)) {
                $qb->andWhere("(LOWER(t.title) LIKE :search ESCAPE '" . Like::ESCAPE . "' OR t.number = :searchNumber)")
                    ->setParameter('searchNumber', (int) $number);
            } else {
                $qb->andWhere("LOWER(t.title) LIKE :search ESCAPE '" . Like::ESCAPE . "'");
            }
            $qb->setParameter('search', '%' . Like::literal(mb_strtolower($filter->search)) . '%');
        }

        return $qb;
    }

    /** Since 2.15.0: the DQL of a user field's value (`IDENTITY()` for a relation, the column itself with user_reference: id). */
    private function userValue(string $path): string
    {
        return $this->users instanceof TaskBoardUsers ? $this->users->identity($path) : 'IDENTITY(' . $path . ')';
    }
}
