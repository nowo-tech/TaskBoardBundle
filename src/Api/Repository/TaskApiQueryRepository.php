<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function mb_strtolower;

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
    ) {
    }

    /**
     * One page of tasks of the given boards, with what serializing them needs.
     *
     * @param list<string> $boardIds boards already checked
     * @param list<object> $assignees users the `assignees[]` filter names
     * @param object|null $owner My tasks: only the tasks it created or is assigned
     *
     * @return array{tasks: list<Task>, lastPage: bool}
     */
    public function taskPage(array $boardIds, TaskApiTaskFilter $filter, array $assignees, ?object $owner): array
    {
        if ($boardIds === [] || ($filter->assigneeIds !== [] && $assignees === [])) {
            return ['tasks' => [], 'lastPage' => true];
        }
        $qb        = $this->filtered($boardIds, $filter, $assignees, $owner);
        $direction = $filter->reverse ? 'DESC' : 'ASC';
        $qb->orderBy(self::ORDER_FIELDS[$filter->orderBy], $direction)->addOrderBy('t.id', $direction);
        $offset = $filter->page * TaskApiTaskFilter::PAGE_SIZE;

        if ($filter->tags === []) {
            /** @var list<array{id: string}> $rows */
            $rows = $qb->select('t.id AS id')->setFirstResult($offset)->setMaxResults(TaskApiTaskFilter::PAGE_SIZE + 1)->getQuery()->getArrayResult();
            $ids  = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        } else {
            // Tags live in a JSON column no database compares alike: read id and tags in one light query, filter, then page.
            /** @var list<array{id: string, tags: mixed}> $rows */
            $rows = $qb->select('t.id AS id', 't.tags AS tags')->getQuery()->getArrayResult();
            $ids  = [];
            foreach ($rows as $row) {
                $tags = is_array($row['tags']) ? array_map(static fn (mixed $tag): string => mb_strtolower(is_string($tag) ? $tag : ''), $row['tags']) : [];
                foreach ($filter->tags as $wanted) {
                    if (in_array($wanted, $tags, true)) {
                        $ids[] = (string) $row['id'];

                        break;
                    }
                }
            }
            $ids = array_slice($ids, $offset, TaskApiTaskFilter::PAGE_SIZE + 1);
        }

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
        /** @var list<TaskDependency> $dependencies */
        $dependencies = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(TaskDependency::class, 'd')
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
        /** @var list<Task> $subtasks */
        $subtasks = $this->entityManager->createQueryBuilder()
            ->select('t', 'm', 'u')
            ->from(Task::class, 't')
            ->leftJoin('t.members', 'm')
            ->leftJoin('m.user', 'u')
            ->where('t.parent IN (:parents)')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('parents', $taskIds)
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $subtasks;
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
            ->select('c', 'a')
            ->from(TaskComment::class, 'c')
            ->leftJoin('c.author', 'a')
            ->where('c.task = :task')
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
            ->where('v.task IN (:tasks)')
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
        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()
            ->select('t', 'c', 'col', 'm', 'u')
            ->from(Task::class, 't')
            ->leftJoin('t.creator', 'c')
            ->leftJoin('t.column', 'col')
            ->leftJoin('t.members', 'm')
            ->leftJoin('m.user', 'u')
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
        if ($filter->statuses !== []) {
            $qb->join('t.column', 'statusColumn')->andWhere('LOWER(statusColumn.name) IN (:statuses)')->setParameter('statuses', $filter->statuses);
        }
        if ($assignees !== []) {
            $qb->andWhere('EXISTS (SELECT am.id FROM ' . TaskMember::class . ' am WHERE am.task = t AND am.memberRole = :assigneeRole AND am.user IN (:assignees))')
                ->setParameter('assignees', $assignees)
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
        $qb->andWhere('t.creator = :owner OR EXISTS (SELECT om.id FROM ' . TaskMember::class . ' om WHERE om.task = t AND om.memberRole = :ownerRole AND om.user = :owner)')
            ->setParameter('owner', $owner)
            ->setParameter('ownerRole', TaskMemberRole::Assignee);
    }
}
