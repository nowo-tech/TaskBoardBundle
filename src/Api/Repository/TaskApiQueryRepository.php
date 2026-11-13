<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Api\Doctrine\JsonTextFunction;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;

use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function mb_strtolower;
use function strtr;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.2.0 (spec 213): what the read API needs from the database, in a fixed number of queries per page whatever its
 * size (FR-010). Filters run in SQL, so `last_page` and every count are those of what the token may read.
 */
final readonly class TaskApiQueryRepository
{
    private const ORDER_FIELDS = ['id' => 't.id', 'created' => 't.createdAt', 'updated' => 't.updatedAt', 'due_date' => 't.dueAt'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private bool $syncEnabled = false,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $users = null,
        // Since 2.17.0 (FR-028): the custom field filters.
        private ?TaskCustomFieldManager $customFields = null,
    ) {
    }

    /**
     * One page of tasks of the given boards, with what serializing them needs.
     *
     * @param list<string> $boardIds boards already checked
     * @param list<object> $assignees users the `assignees[]` filter names
     * @param object|null $owner My tasks: only the tasks it created or is assigned
     * @param array<string, TaskCustomField> $fields since 2.17.0: the native fields `custom_fields` may name, by id
     *
     * @throws TaskApiRefusal
     *
     * @return array{tasks: list<Task>, lastPage: bool}
     */
    public function taskPage(array $boardIds, TaskApiTaskFilter $filter, array $assignees, ?object $owner, array $fields = []): array
    {
        if ($boardIds === [] || ($filter->assigneeIds !== [] && $assignees === [])) {
            return ['tasks' => [], 'lastPage' => true];
        }
        $qb = $this->filtered($boardIds, $filter, $assignees, $owner);
        // Since 2.17.0 (FR-028): `custom_fields`, on the native fields of these boards.
        if ($filter->customFields !== []) {
            try {
                ($this->customFields ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND))->filter($qb, 't', $filter->customFields, $fields);
            } catch (TaskCustomFieldRefusedException) {
                throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid custom_fields');
            }
        }
        $direction = $filter->reverse ? 'DESC' : 'ASC';
        // @igor-ignore - QueryBuilder created per call by filtered(); not shared.
        $qb->orderBy(self::ORDER_FIELDS[$filter->orderBy], $direction)->addOrderBy('t.id', $direction);
        $offset = $filter->page * TaskApiTaskFilter::PAGE_SIZE;

        /** @var list<array{id: string}> $rows */
        // @igor-ignore - QueryBuilder created per call by filtered(); not shared.
        $rows = $qb->select('t.id AS id')->setFirstResult($offset)->setMaxResults(TaskApiTaskFilter::PAGE_SIZE + 1)->getQuery()->getArrayResult();
        $ids  = array_map(static fn (array $row): string => (string) $row['id'], $rows);

        $lastPage = count($ids) <= TaskApiTaskFilter::PAGE_SIZE;
        $ids      = array_slice($ids, 0, TaskApiTaskFilter::PAGE_SIZE);

        return ['tasks' => $this->load($ids), 'lastPage' => $lastPage];
    }

    /**
     * Open (not archived) tasks per board, as the token may count them.
     *
     * @param list<string> $boardIds
     *
     * @return array<string, int>
     */
    public function countByBoard(array $boardIds, ?object $owner): array
    {
        if ($boardIds === []) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(t.board) AS board', 'COUNT(t.id) AS tasks')
            ->from(Task::class, 't')
            ->where('t.board IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('boards', $boardIds)
            ->groupBy('t.board');
        $this->onlyOwn($qb, $owner);
        $counts = [];
        /** @var list<array{board: string, tasks: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $counts[(string) $row['board']] = (int) $row['tasks'];
        }

        return $counts;
    }

    /**
     * The tags used on the open tasks of the boards, sorted.
     *
     * @param list<string> $boardIds
     *
     * @return list<string>
     */
    public function tagsOf(array $boardIds): array
    {
        if ($boardIds === []) {
            return [];
        }
        /** @var list<array{tags: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.tags AS tags')
            ->from(Task::class, 't')
            ->where('t.board IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('boards', $boardIds)
            ->getQuery()
            ->getArrayResult();
        $tags = [];
        foreach ($rows as $row) {
            foreach (is_array($row['tags']) ? $row['tags'] : [] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[mb_strtolower($tag)] = $tag;
                }
            }
        }
        ksort($tags);

        return array_values($tags);
    }

    /**
     * The dependencies touching the tasks, in one query.
     *
     * @param list<string> $taskIds
     *
     * @return list<TaskDependency>
     */
    public function dependenciesOf(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $builder = $this->entityManager->createQueryBuilder()
            // Both ends with their members: whether the token may open the other end is asked of each (audit IA-I7).
            ->select('d', 's', 'sm', 'e', 'em')
            ->from(TaskDependency::class, 'd')
            ->join('d.sourceTask', 's')
            ->leftJoin('s.members', 'sm')
            ->join('d.targetTask', 'e')
            ->leftJoin('e.members', 'em');
        $this->users()->eager($builder, 'sm.user', 'su');
        $this->users()->eager($builder, 'em.user', 'eu');
        /** @var list<TaskDependency> $dependencies */
        $dependencies = $builder
            ->where('d.sourceTask IN (:tasks) OR d.targetTask IN (:tasks)')
            ->setParameter('tasks', $taskIds)
            ->getQuery()
            ->getResult();

        return $dependencies;
    }

    /**
     * The subtasks of the tasks (checklist items among them), with their members.
     *
     * @param list<string> $taskIds
     *
     * @return list<Task>
     */
    public function subtasksOf(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $subtasks = $this->entityManager->createQueryBuilder()
            ->select('t', 'm')
            ->from(Task::class, 't')
            ->leftJoin('t.members', 'm');
        $this->users()->eager($subtasks, 'm.user', 'u');
        /** @var list<Task> $result */
        $result = $subtasks
            ->where('t.parent IN (:parents)')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('parents', $taskIds)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Minutes logged by hand per task, in one query (the timers' time is on the task).
     *
     * @param list<string> $taskIds
     *
     * @return array<string, int>
     */
    public function manualMinutesOf(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        /** @var list<array{task: string, minutes: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(e.task) AS task', 'SUM(e.minutes) AS minutes')
            ->from(TaskTimeEntry::class, 'e')
            ->where('e.task IN (:tasks)')
            ->setParameter('tasks', $taskIds)
            ->groupBy('e.task')
            ->getQuery()
            ->getArrayResult();
        $minutes = [];
        foreach ($rows as $row) {
            $minutes[(string) $row['task']] = (int) $row['minutes'];
        }

        return $minutes;
    }

    /**
     * A page of a task's comments, newest first, older than (`$before`, `$beforeId`) when given. Deleted ones are left out.
     *
     * @return list<TaskComment>
     */
    public function comments(Task $task, ?DateTimeImmutable $before, ?string $beforeId, int $limit): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(TaskComment::class, 'c');
        $this->users()->eager($qb, 'c.author', 'a');
        $qb->where('c.task = :task')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('task', $task)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit);
        if ($before instanceof DateTimeImmutable) {
            $qb->andWhere($beforeId !== null ? 'c.createdAt < :before OR (c.createdAt = :before AND c.id < :beforeId)' : 'c.createdAt < :before')
                ->setParameter('before', $before);
            if ($beforeId !== null) {
                $qb->setParameter('beforeId', $beforeId);
            }
        }
        /** @var list<TaskComment> $comments */
        $comments = $qb->getQuery()->getResult();

        return $comments;
    }

    /**
     * Whether the board follows a provider (never without `sync.enabled`).
     */
    public function hasConnection(string $boardId): bool
    {
        if (!$this->syncEnabled) {
            return false;
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(TaskBoardConnection::class, 'c')
            ->where('c.board = :board')
            ->setParameter('board', $boardId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The imported custom fields of a board (none without `sync.enabled`).
     *
     * @return list<TaskSyncField>
     */
    public function fieldsOf(string $boardId): array
    {
        if (!$this->syncEnabled) {
            return [];
        }
        /** @var list<TaskSyncField> $fields */
        $fields = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(TaskSyncField::class, 'f')
            ->where('f.board = :board')
            ->setParameter('board', $boardId)
            ->orderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $fields;
    }

    /**
     * The imported field values of the tasks, erased ones left out, by task id.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, list<TaskSyncFieldValue>>
     */
    public function fieldValuesOf(array $taskIds): array
    {
        if (!$this->syncEnabled || $taskIds === []) {
            return [];
        }
        /** @var list<TaskSyncFieldValue> $values */
        $values = $this->entityManager->createQueryBuilder()
            ->select('v', 'f')
            ->from(TaskSyncFieldValue::class, 'v')
            ->join('v.field', 'f')
            ->join('v.task', 't')
            ->where('v.task IN (:tasks)')
            // Since 2.3.0 (spec 217, owner decision 4): only the fields of the task's own board, as the panel shows them.
            ->andWhere('f.board = t.board')
            ->setParameter('tasks', $taskIds)
            ->orderBy('f.position', 'ASC')
            ->getQuery()
            ->getResult();
        $byTask = [];
        foreach ($values as $value) {
            if (!$value->isErased()) {
                $byTask[$value->getTask()->getId()][] = $value;
            }
        }

        return $byTask;
    }

    /**
     * The tasks, in the order of `$ids`, with their creator, column, members and members' users.
     *
     * @param list<string> $ids
     *
     * @return list<Task>
     */
    public function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $builder = $this->entityManager->createQueryBuilder()
            // The boards' columns too: each task shows its status among them, one query for all boards (audit IA-I8).
            ->select('t', 'col', 'm', 'b', 'bc')
            ->from(Task::class, 't')
            ->join('t.board', 'b')
            ->leftJoin('b.columns', 'bc')
            ->leftJoin('t.column', 'col')
            ->leftJoin('t.members', 'm');
        $this->users()->eager($builder, 't.creator', 'c');
        $this->users()->eager($builder, 'm.user', 'u');
        /** @var list<Task> $tasks */
        $tasks = $builder
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->getId()] = $task;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $boardIds
     * @param list<object> $assignees
     */
    private function filtered(array $boardIds, TaskApiTaskFilter $filter, array $assignees, ?object $owner): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Task::class, 't')
            ->where('t.board IN (:boards)')
            ->setParameter('boards', $boardIds);
        $qb->andWhere($filter->archived ? 't.archivedAt IS NOT NULL' : 't.archivedAt IS NULL');
        if (!$filter->subtasks) {
            $qb->andWhere('t.parent IS NULL');
        }
        if (!$filter->includeClosed) {
            $qb->andWhere('t.completedAt IS NULL');
        }
        if ($filter->tags !== []) {
            // Audit IA-L3: in SQL, so the page and `last_page` cost what any filter costs. Tags are stored as a JSON list of
            // strings: a quoted name matches one element exactly (case aside for ASCII).
            $any = [];
            // MySQL keeps JSON normalised (unicode and slashes unescaped); the others keep Doctrine's encoding.
            $flags = $this->entityManager->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0;
            foreach (array_values($filter->tags) as $index => $tag) {
                $any[] = 'LOWER(' . JsonTextFunction::NAME . "(t.tags)) LIKE :tag{$index} ESCAPE '!'";
                $qb->setParameter('tag' . $index, '%' . strtr(mb_strtolower((string) json_encode($tag, $flags)), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
            }
            $qb->andWhere(implode(' OR ', $any));
        }
        if ($filter->statuses !== []) {
            $qb->join('t.column', 'statusColumn')->andWhere('LOWER(statusColumn.name) IN (:statuses)')->setParameter('statuses', $filter->statuses);
        }
        if ($assignees !== [] && $this->idMode()) {
            // Since 2.15.0 (user_reference: id; audit OC-M2): the tasks whose assignee id is exactly one of them.
            $qb->andWhere('t.id IN (:exactAssigneeTasks)')->setParameter('exactAssigneeTasks', $this->exactTaskIds($assignees, false));
        } elseif ($assignees !== []) {
            $qb->andWhere('EXISTS (SELECT am.id FROM ' . TaskMember::class . ' am WHERE am.task = t AND am.memberRole = :assigneeRole AND am.user IN (:assignees))')
                ->setParameter('assignees', array_map($this->userParameter(...), $assignees))
                ->setParameter('assigneeRole', TaskMemberRole::Assignee);
        }
        $columns = ['due_date' => 't.dueAt', 'date_created' => 't.createdAt', 'date_updated' => 't.updatedAt', 'date_done' => 't.completedAt'];
        foreach ($filter->dates as $name => $date) {
            $field = $columns[substr($name, 0, -3)];
            $qb->andWhere($field . (str_ends_with($name, '_gt') ? ' > :' : ' < :') . $name)->setParameter($name, $date);
        }
        $this->onlyOwn($qb, $owner);

        return $qb;
    }

    /** My tasks: created by the person, or assigned to them. Watching does not count. */
    private function onlyOwn(QueryBuilder $qb, ?object $owner): void
    {
        if ($owner === null) {
            return;
        }
        if ($this->idMode()) {
            // Since 2.15.0 (user_reference: id; audit OC-M2): created by or assigned to exactly this id.
            $qb->andWhere('t.id IN (:exactOwnTasks)')->setParameter('exactOwnTasks', $this->exactTaskIds([$owner], true));

            return;
        }
        $qb->andWhere('t.creator = :owner OR EXISTS (SELECT om.id FROM ' . TaskMember::class . ' om WHERE om.task = t AND om.memberRole = :ownerRole AND om.user = :owner)')
            ->setParameter('owner', $this->userParameter($owner))
            ->setParameter('ownerRole', TaskMemberRole::Assignee);
    }

    /** Since 2.15.0: a user as a DQL parameter (the object for a relation, its id with user_reference: id). */
    private function userParameter(object $user): object|string|null
    {
        return $this->users instanceof TaskBoardUsers ? $this->users->parameter($user) : $user;
    }

    /** Since 2.15.0: the user reference mode (a relation, as 2.14, when the service is built by hand). */
    private function users(): TaskBoardUsers
    {
        return $this->users ?? new TaskBoardUsers();
    }

    private function idMode(): bool
    {
        return $this->users instanceof TaskBoardUsers && $this->users->idMode();
    }

    /**
     * Since 2.15.0 (audit OC-M2): the tasks a user is the assignee (and, with `$creator`, the creator) of, by exact id.
     * The database finds the candidates; PHP keeps the rows whose id is byte for byte one asked. Never empty (a match on
     * no task is an impossible id), so the IN list stays valid.
     *
     * @param list<object> $users
     *
     * @return list<string>
     */
    private function exactTaskIds(array $users, bool $creator): array
    {
        $ids = [];
        foreach ($users as $user) {
            $id = ActorId::of($user);
            if ($id !== null && TaskBoardUserRef::isValid($id)) {
                $ids[$id] = true;
            }
        }
        $tasks = [];
        if ($ids !== []) {
            /** @var list<array{taskId: mixed, ref: mixed}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(m.task) AS taskId', 'm.user AS ref')
                ->from(TaskMember::class, 'm')
                ->where('m.memberRole = :role')
                ->andWhere('m.user IN (:ids)')
                ->setParameter('role', TaskMemberRole::Assignee)
                ->setParameter('ids', array_keys($ids))
                ->getQuery()
                ->getArrayResult();
            if ($creator) {
                /** @var list<array{taskId: mixed, ref: mixed}> $created */
                $created = $this->entityManager->createQueryBuilder()
                    ->select('t.id AS taskId', 't.creator AS ref')
                    ->from(Task::class, 't')
                    ->where('t.creator IN (:ids)')
                    ->setParameter('ids', array_keys($ids))
                    ->getQuery()
                    ->getArrayResult();
                $rows = [...$rows, ...$created];
            }
            foreach ($rows as $row) {
                $ref = $row['ref'] instanceof TaskBoardUserRef ? $row['ref']->id : null;
                if ($ref !== null && isset($ids[$ref])) {
                    $tasks[(string) $row['taskId']] = true;
                }
            }
        }

        return $tasks === [] ? [''] : array_keys($tasks);
    }
}
