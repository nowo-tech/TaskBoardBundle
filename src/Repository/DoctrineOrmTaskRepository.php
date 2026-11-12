<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Dto\TaskCalendarFilter;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\Like;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use SortDirection;

use function array_chunk;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function max;
use function mb_strtolower;
use function uksort;

final readonly class DoctrineOrmTaskRepository implements TaskRepositoryInterface, BoundedTaskRepositoryInterface, ArchiveAwareTaskRepositoryInterface, TaskCalendarRepositoryInterface
{
    /** Since 1.23: ids per query of {@see self::findImportedByExternalIds()}. */
    private const ID_CHUNK = 500;

    /** Collections of the task page => the to-one relation of each element fetched with it. */
    private const DETAIL_RELATIONS = [
        'members'              => 'user',
        'links'                => null,
        'documents'            => null,
        'outgoingDependencies' => 'targetTask',
        'incomingDependencies' => 'sourceTask',
        'changeHistory'        => 'user',
        'timeEntries'          => 'user',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $users = null,
    ) {
    }

    public function save(Task $task): void
    {
        $this->entityManager->persist($task);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function findById(string $id): ?Task
    {
        return $this->entityManager->find(Task::class, $id);
    }

    public function findByBoard(TaskBoard $board, bool $includeCompleted = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board)
            ->orderBy('t.position', SortDirection::Ascending);

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findByBoardWithin(TaskBoard $board, bool $includeCompleted, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board)
            ->orderBy('t.position', SortDirection::Ascending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit));

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();
        if ($result !== []) {
            // Members and their users of the loaded tasks in one query (the cards show the assignees).
            $builder = $this->entityManager->createQueryBuilder()
                ->select('t', 'taskMember')
                ->from(Task::class, 't')
                ->leftJoin('t.members', 'taskMember');
            ($this->users ?? new TaskBoardUsers())->eager($builder, 'taskMember.user', 'memberUser');
            $builder->where('t IN (:tasks)')
                ->setParameter('tasks', $result)
                ->getQuery()
                ->getResult();
        }

        return $result;
    }

    public function countByBoard(TaskBoard $board, bool $includeCompleted = false): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board);

        if (!$includeCompleted) {
            $qb->andWhere('t.completedAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findForDetail(string $id): ?Task
    {
        $task = $this->entityManager->createQueryBuilder()
            ->select('t', 'board', 'col', 'parent')
            ->from(Task::class, 't')
            ->innerJoin('t.board', 'board')
            ->leftJoin('t.column', 'col')
            ->leftJoin('t.parent', 'parent')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$task instanceof Task) {
            return null;
        }

        // One query per collection (no cartesian product): each one fills the collection of the same managed task.
        foreach (self::DETAIL_RELATIONS as $relation => $nested) {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('t', 'rel')
                ->from(Task::class, 't')
                ->leftJoin('t.' . $relation, 'rel')
                ->where('t = :task')
                ->setParameter('task', $task);
            // Since 2.15.0: no user to join with user_reference: id (the rows keep the id).
            if ($nested !== null && !($nested === 'user' && $this->users instanceof TaskBoardUsers && $this->users->idMode())) {
                $qb->addSelect('nested')->leftJoin('rel.' . $nested, 'nested');
            }

            $qb->getQuery()->getResult();
        }

        return $task;
    }

    public function findTrackableForUser(string $userId, ?string $search, int $limit, int $offset): array
    {
        // Since 1.25: the joins only select ids (a subquery), so no DISTINCT runs over the task row: PostgreSQL has no
        // equality operator for its json columns (tags), and `SELECT DISTINCT t` failed there.
        $ids = $this->entityManager->createQueryBuilder()
            ->select('t2.id')
            ->from(Task::class, 't2')
            ->leftJoin('t2.members', 'taskMember', 'WITH', 'taskMember.memberRole = :assigneeRole')
            ->leftJoin('t2.board', 'board')
            ->leftJoin('board.team', 'team')
            ->leftJoin(TeamMember::class, 'tm', 'WITH', 'tm.team = team');
        if ($this->users instanceof TaskBoardUsers && $this->users->idMode()) {
            // Since 2.15.0 (database.user_reference: id): the members' id columns themselves.
            $ids->where('taskMember.user = :userId OR tm.user = :userId');
        } else {
            $ids->leftJoin('taskMember.user', 'assignee')
                ->leftJoin('tm.user', 'tmUser')
                ->where('assignee.id = :userId OR tmUser.id = :userId');
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.id IN (' . $ids->getDQL() . ')')
            ->andWhere('t.completedAt IS NULL')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('assigneeRole', TaskMemberRole::Assignee)
            ->setParameter('userId', $userId)
            ->orderBy('t.createdAt', SortDirection::Descending)
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset));

        if ($search !== null && $search !== '') {
            // Since 1.25 (audit FP-I12): `%`, `_` and `!` in the search match literally (ESCAPE '!').
            $qb->andWhere("LOWER(t.title) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%' . Like::literal(mb_strtolower($search)) . '%');
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findByHostObject(string $objectType, string $objectId): array
    {
        /** @var list<Task> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            // Since 1.25: a subquery instead of `SELECT DISTINCT t` (PostgreSQL cannot compare the json columns).
            ->where('t.id IN (SELECT IDENTITY(link.task) FROM ' . TaskLink::class . ' link WHERE link.linkType = :linkType AND link.objectType = :objectType AND link.objectId = :objectId)')
            ->setParameter('linkType', TaskLinkType::HostObject)
            ->setParameter('objectType', $objectType)
            ->setParameter('objectId', $objectId)
            ->orderBy('t.createdAt', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findByBoardIncludingArchived(TaskBoard $board): array
    {
        /** @var list<Task> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->setParameter('board', $board)
            ->orderBy('t.position', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findArchivedByBoard(TaskBoard $board, int $limit): array
    {
        /** @var list<Task> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NOT NULL')
            ->setParameter('board', $board)
            ->orderBy('t.archivedAt', SortDirection::Descending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findImportedByExternalIds(TaskBoard $board, string $scope, array $externalIds): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($externalIds)), self::ID_CHUNK) as $chunk) {
            /** @var list<TaskLink> $links */
            $links = $this->entityManager->createQueryBuilder()
                ->select('l', 't')
                ->from(TaskLink::class, 'l')
                ->innerJoin('l.task', 't')
                ->where('t.board = :board')
                ->andWhere('l.importSource = :scope')
                ->andWhere('l.externalId IN (:ids)')
                ->setParameter('board', $board)
                ->setParameter('scope', $scope)
                ->setParameter('ids', $chunk)
                ->orderBy('l.createdAt', SortDirection::Ascending)
                ->getQuery()
                ->getResult();
            foreach ($links as $link) {
                $found[(string) $link->getExternalId()] ??= [$link->getTask(), $link];
            }
        }

        return $found;
    }

    public function findForCalendar(TaskBoard $board, DateTimeImmutable $from, DateTimeImmutable $to, TaskCalendarFilter $filter, int $limit): array
    {
        $qb = $this->inRange($this->calendarQuery($board, $filter), $from, $to)
            ->select('t', 'col', 'COALESCE(t.startAt, t.dueAt) AS HIDDEN firstDay')
            ->leftJoin('t.column', 'col')
            ->orderBy('firstDay', SortDirection::Ascending)
            ->addOrderBy('t.position', SortDirection::Ascending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit));

        if ($filter->tag !== null) {
            $ids = $this->idsWithTag($this->inRange($this->calendarQuery($board, $filter), $from, $to), $filter->tag);
            if ($ids === []) {
                return [];
            }
            // @igor-ignore - A query builder made for this call, not a shared service.
            $qb->andWhere('t.id IN (:taggedIds)')->setParameter('taggedIds', $ids);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function countForCalendar(TaskBoard $board, DateTimeImmutable $from, DateTimeImmutable $to, TaskCalendarFilter $filter): int
    {
        return $this->countOf($this->inRange($this->calendarQuery($board, $filter), $from, $to), $filter);
    }

    public function countUndated(TaskBoard $board, TaskCalendarFilter $filter): int
    {
        return $this->countOf($this->calendarQuery($board, $filter)->andWhere('t.startAt IS NULL')->andWhere('t.dueAt IS NULL'), $filter);
    }

    public function findCalendarAssignees(TaskBoard $board): array
    {
        $idMode  = $this->users instanceof TaskBoardUsers && $this->users->idMode();
        $builder = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(TaskMember::class, 'm')
            ->innerJoin('m.task', 't');
        if (!$idMode) {
            $builder->addSelect('u')->innerJoin('m.user', 'u');
        }
        /** @var list<TaskMember> $members */
        $members = $builder
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('m.memberRole = :role')
            ->setParameter('board', $board)
            ->setParameter('role', TaskMemberRole::Assignee)
            ->getQuery()
            ->getResult();

        $people = [];
        foreach ($members as $member) {
            $user = $member->getUser();
            // Since 2.15.0 (user_reference: id): the reference's id, and the host user for the label.
            $id = $idMode ? ActorId::of($user) : $this->entityManager->getUnitOfWork()->getSingleIdentifierValue($user);
            if ($id !== null) {
                $people[(string) $id] = $idMode && $this->users instanceof TaskBoardUsers ? ($this->users->resolve($user) ?? $user) : $user;
            }
        }

        // Since 2.14.1 (audit NN-I2): a deterministic order, by label then id (the database order of the join differs
        // between MySQL, PostgreSQL and SQLite, and from one schema to the next).
        uksort($people, static fn (int|string $a, int|string $b): int => [UserLabel::of($people[$a]), (string) $a] <=> [UserLabel::of($people[$b]), (string) $b]);

        return $people;
    }

    private function calendarQuery(TaskBoard $board, TaskCalendarFilter $filter): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Task::class, 't')
            ->where('t.board = :board')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('board', $board);

        if (!$filter->completed) {
            $qb->andWhere('t.completedAt IS NULL');
        }
        if (!$filter->subtasks) {
            $qb->andWhere('t.parent IS NULL');
        }
        if ($filter->priority instanceof TaskPriority) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $filter->priority);
        }
        if ($filter->columnId !== null) {
            $qb->andWhere('IDENTITY(t.column) = :columnId')->setParameter('columnId', $filter->columnId);
        }
        if ($filter->assignee !== null) {
            $qb->andWhere('t.id IN (SELECT IDENTITY(am.task) FROM ' . TaskMember::class . ' am WHERE am.memberRole = :assigneeRole AND ' . $this->userValue('am.user') . ' = :assignee)')
                ->setParameter('assigneeRole', TaskMemberRole::Assignee)
                ->setParameter('assignee', $filter->assignee);
        }

        return $qb;
    }

    /** Tasks with a date overlapping the days from `$from` up to, not including, `$to`. */
    private function inRange(QueryBuilder $qb, DateTimeImmutable $from, DateTimeImmutable $to): QueryBuilder
    {
        return $qb
            ->andWhere('t.startAt IS NOT NULL OR t.dueAt IS NOT NULL')
            ->andWhere('COALESCE(t.startAt, t.dueAt) < :calendarTo')
            ->andWhere('COALESCE(t.dueAt, t.startAt) >= :calendarFrom')
            ->setParameter('calendarFrom', $from, 'datetime_immutable')
            ->setParameter('calendarTo', $to, 'datetime_immutable');
    }

    private function countOf(QueryBuilder $qb, TaskCalendarFilter $filter): int
    {
        if ($filter->tag !== null) {
            return count($this->idsWithTag($qb, $filter->tag));
        }

        return (int) $qb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * The ids of the matching tasks with a tag. Tags are a JSON column, which no portable query can search, so they are compared
     * here; the query reads two columns of tasks the date range already bounds.
     *
     * @return list<string>
     */
    private function idsWithTag(QueryBuilder $qb, string $tag): array
    {
        $wanted = mb_strtolower($tag);
        $ids    = [];
        /** @var list<array{id: string, tags: mixed}> $rows */
        $rows = $qb->select('t.id', 't.tags')->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $tags = is_array($row['tags']) ? array_map(static fn (mixed $value): string => mb_strtolower((string) $value), $row['tags']) : [];
            if (in_array($wanted, $tags, true)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /** Since 2.15.0: the DQL of a user field's value (`IDENTITY()` for a relation, the column itself with user_reference: id). */
    private function userValue(string $path): string
    {
        return $this->users instanceof TaskBoardUsers ? $this->users->identity($path) : 'IDENTITY(' . $path . ')';
    }
}
