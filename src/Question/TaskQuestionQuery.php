<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use SortDirection;

use function array_chunk;
use function array_filter;
use function array_intersect;
use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_string;
use function max;
use function min;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.enabled`): the reads of the questions — a task's questions (its page,
 * drawer and the Task API), the open counts of a page of tasks (the *Waiting for answer* badge, one query), the answered
 * questions of a board (a host's *Decisions* view and export) and the candidates of *my open questions*.
 *
 * It makes no permission decision: the caller decides who may read what (the pages and the API check the task and board
 * access of every row they show). Every id read back is compared exactly (REQ-SEC-006). Stateless (worker mode).
 */
final readonly class TaskQuestionQuery
{
    /** The largest IN list of one query. */
    public const CHUNK = 500;

    /** The most candidates *my open questions* reads (open questions of one person: far fewer in practice). */
    public const MY_QUESTIONS_CAP = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?TaskBoardUsers $users = null,
    ) {
    }

    /**
     * The task's questions, oldest first, with their options, recipients and answers (four queries whatever their number).
     *
     * @return list<TaskQuestion>
     */
    public function forTask(Task $task): array
    {
        /** @var list<TaskQuestion> $questions */
        $questions = $this->entityManager->createQuery('SELECT q FROM ' . TaskQuestion::class . ' q WHERE q.task = :task ORDER BY q.askedAt ASC, q.id ASC')
            ->setParameter('task', $task->getId())
            ->getResult();
        $questions = array_values(array_filter($questions, static fn (TaskQuestion $question): bool => $question->getTask()->getId() === $task->getId()));
        $this->hydrate($questions);

        return $questions;
    }

    /**
     * How many open questions each task has (tasks with none are left out): one query per {@see self::CHUNK} ids.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, int>
     */
    public function openCounts(array $taskIds): array
    {
        $counts = [];
        foreach (array_chunk(array_values($taskIds), self::CHUNK) as $chunk) {
            /** @var list<array{task: string, openCount: int|string}> $rows */
            $rows = $this->entityManager->createQuery('SELECT IDENTITY(q.task) AS task, COUNT(q.id) AS openCount FROM ' . TaskQuestion::class . ' q WHERE q.task IN (:tasks) AND q.state = :open GROUP BY q.task')
                ->setParameter('tasks', $chunk)
                ->setParameter('open', TaskQuestionState::Open->value)
                ->getArrayResult();
            foreach ($rows as $row) {
                $counts[(string) $row['task']] = (int) $row['openCount'];
            }
        }

        return $counts;
    }

    /**
     * The answered questions of a board's tasks (withdrawn ones left out), by the time of the answer in force then id, each
     * with its answers. Keyset pages: `$afterId` is the last question of the previous page.
     *
     * @return list<TaskQuestionSnapshot>
     */
    public function answeredOnBoard(string $boardId, ?DateTimeImmutable $since = null, int $limit = 100, ?string $afterId = null): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('q', 't', 'b')->from(TaskQuestion::class, 'q')->join('q.task', 't')->join('t.board', 'b')
            ->where('b.id = :board AND q.state = :answered')
            ->setParameter('board', $boardId)
            ->setParameter('answered', TaskQuestionState::Answered->value)
            ->orderBy('q.answeredAt', SortDirection::Ascending)->addOrderBy('q.id', SortDirection::Ascending)
            ->setMaxResults(max(1, min($limit, 500)));
        if ($since instanceof DateTimeImmutable) {
            $builder->andWhere('q.answeredAt >= :since')->setParameter('since', $since);
        }
        $after = $afterId === null ? null : $this->entityManager->find(TaskQuestion::class, $afterId);
        // Audit TE-L3: a cursor of another board (or tenant) is ignored, so its answer time never shows through the page.
        if ($after instanceof TaskQuestion && $after->getId() === $afterId && $after->getTask()->getBoard()->getId() === $boardId && $after->getAnsweredAt() instanceof DateTimeImmutable) {
            $builder->andWhere('q.answeredAt > :afterAt OR (q.answeredAt = :afterAt AND q.id > :afterId)')
                ->setParameter('afterAt', $after->getAnsweredAt())
                ->setParameter('afterId', $after->getId());
        }
        /** @var list<TaskQuestion> $questions */
        $questions = $builder->getQuery()->getResult();
        $questions = array_values(array_filter($questions, static fn (TaskQuestion $question): bool => $question->getTask()->getBoard()->getId() === $boardId));
        $this->hydrate($questions);

        return array_map(TaskQuestionSnapshot::of(...), $questions);
    }

    /**
     * Open questions addressed to one of the subject ids, due first (no due date last), then oldest; at most
     * {@see self::MY_QUESTIONS_CAP}. Candidates only: the caller re-checks the access of each.
     *
     * @param list<string> $subjectIds
     *
     * @return list<TaskQuestion>
     */
    public function openForSubjects(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }
        /** @var list<TaskQuestion> $questions */
        $questions = $this->openQuery()
            ->andWhere('q.id IN (SELECT IDENTITY(r.question) FROM ' . TaskQuestionRecipient::class . ' r WHERE r.subjectId IN (:subjects))')
            ->setParameter('subjects', array_values($subjectIds))
            ->getQuery()->getResult();
        // REQ-SEC-006: a recipient matched by a case- or accent-insensitive collation is not this person.
        $questions = array_values(array_filter($questions, static fn (TaskQuestion $question): bool => array_intersect($subjectIds, array_map(static fn (TaskQuestionRecipient $recipient): string => $recipient->getSubjectId(), $question->getRecipients())) !== []));
        $this->hydrate($questions);

        return $questions;
    }

    /**
     * Open questions `$user` asked, ordered as {@see self::openForSubjects()}.
     *
     * @return list<TaskQuestion>
     */
    public function openAskedBy(object $user): array
    {
        $id = ActorId::of($user);
        if ($id === null) {
            return [];
        }
        /** @var list<TaskQuestion> $questions */
        $questions = $this->openQuery()
            ->andWhere(($this->users?->identity('q.askedBy') ?? 'IDENTITY(q.askedBy)') . ' = :asker')
            // Since 2.32.0 (audit ZAK-2): the house binding (the entity in relation mode, so Doctrine converts its id with the
            // id's own type, e.g. a binary Uuid; the id in id mode); the rows are still compared exactly below.
            ->setParameter('asker', $this->users?->parameter($user) ?? $user)
            ->getQuery()->getResult();
        $questions = array_values(array_filter($questions, static fn (TaskQuestion $question): bool => ActorId::of($question->getAskedBy()) === $id));
        $this->hydrate($questions);

        return $questions;
    }

    /**
     * Since 2.32.0 (Beacon request, *to answer* badge): the boards of the open questions addressed to one of the subject
     * ids, each once; `$tenantKey` narrows them to that tenant (the caller compares the key exactly). One query that reads
     * boards only, never a question or a task: the caller decides which boards the viewer may see.
     *
     * @param list<string> $subjectIds
     *
     * @return list<TaskBoard>
     */
    public function boardsOpenForSubjects(array $subjectIds, ?string $tenantKey = null): array
    {
        if ($subjectIds === []) {
            return [];
        }
        $builder = $this->entityManager->createQueryBuilder()
            ->select('b')->from(TaskBoard::class, 'b')
            ->where('b.id IN (SELECT IDENTITY(bt.board) FROM ' . TaskQuestionRecipient::class . ' br JOIN br.question bq JOIN bq.task bt WHERE bq.state = :open AND br.subjectId IN (:subjects))')
            ->setParameter('open', TaskQuestionState::Open->value)
            ->setParameter('subjects', array_values($subjectIds));
        if ($tenantKey !== null) {
            $builder->andWhere('b.tenantKey = :tenant')->setParameter('tenant', $tenantKey);
        }
        /** @var list<TaskBoard> $boards */
        $boards = $builder->getQuery()->getResult();

        return $boards;
    }

    /**
     * Since 2.32.0: the tasks of `$boardIds` with an open question addressed to one of the subject ids, with their members
     * (one query), for a host whose TASK_ACCESS_CHECK listener decides each task. The questions are not read.
     *
     * @param list<string> $subjectIds
     * @param list<string> $boardIds
     *
     * @return list<Task>
     */
    public function tasksOpenForSubjects(array $subjectIds, array $boardIds): array
    {
        if ($subjectIds === [] || $boardIds === []) {
            return [];
        }
        $builder = $this->entityManager->createQueryBuilder()
            ->select('t', 'm')->from(Task::class, 't')->leftJoin('t.members', 'm')
            ->where('t.id IN (SELECT IDENTITY(tq.task) FROM ' . TaskQuestionRecipient::class . ' tr JOIN tr.question tq WHERE tq.state = :open AND tr.subjectId IN (:subjects))')
            ->setParameter('open', TaskQuestionState::Open->value)
            ->setParameter('subjects', array_values($subjectIds));
        $builder->andWhere($this->inLists($builder, 'IDENTITY(t.board)', $boardIds, 'boards'));
        /** @var list<Task> $tasks */
        $tasks = $builder->getQuery()->getResult();

        return $tasks;
    }

    /**
     * Since 2.32.0: how many open questions are addressed to one of the subject ids on `$boardIds` (every task of those
     * boards: the caller passes the boards the viewer may see whole). One query whatever the number of questions, no
     * entity read.
     *
     * @param list<string> $subjectIds
     * @param list<string> $boardIds
     */
    public function countOpenForSubjects(array $subjectIds, array $boardIds): int
    {
        if ($subjectIds === [] || $boardIds === []) {
            return 0;
        }
        $builder = $this->countQuery($subjectIds);
        $builder->andWhere($this->inLists($builder, 'IDENTITY(ct.board)', $boardIds, 'boards'));

        return $this->exactCount($builder, $subjectIds);
    }

    /**
     * Since 2.32.0: how many open questions are addressed to one of the subject ids on `$taskIds`: one query (the ids
     * in IN lists of {@see self::CHUNK}), no entity read.
     *
     * @param list<string> $subjectIds
     * @param list<string> $taskIds
     */
    public function countOpenOnTasks(array $subjectIds, array $taskIds): int
    {
        if ($subjectIds === [] || $taskIds === []) {
            return 0;
        }
        $builder = $this->countQuery($subjectIds);
        $builder->andWhere($this->inLists($builder, 'ct.id', $taskIds, 'tasks'));

        return $this->exactCount($builder, $subjectIds);
    }

    /**
     * The recipient rows of the open questions whose subject id SQL matched: their question id and subject id only.
     *
     * @param list<string> $subjectIds
     */
    private function countQuery(array $subjectIds): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(cr.question) AS questionId', 'cr.subjectId AS subjectId')
            ->from(TaskQuestionRecipient::class, 'cr')->join('cr.question', 'cq')->join('cq.task', 'ct')
            ->where('cq.state = :open AND cr.subjectId IN (:subjects)')
            ->setParameter('open', TaskQuestionState::Open->value)
            ->setParameter('subjects', array_values($subjectIds));
    }

    /**
     * The distinct questions of the rows whose subject id is exactly one of `$subjectIds` (REQ-SEC-006: a folding collation
     * matches `User:1` for `user:1`, so SQL cannot count them alone; the rows are two short scalars each).
     *
     * @param list<string> $subjectIds
     */
    private function exactCount(QueryBuilder $builder, array $subjectIds): int
    {
        /** @var list<array{questionId: mixed, subjectId: mixed}> $rows */
        $rows      = $builder->getQuery()->getScalarResult();
        $questions = [];
        foreach ($rows as $row) {
            if (is_string($row['subjectId']) && in_array($row['subjectId'], $subjectIds, true)) {
                $questions[(string) $row['questionId']] = true;
            }
        }

        return count($questions);
    }

    /**
     * `expr IN (...)` over lists of at most {@see self::CHUNK} values (one bound parameter each); `1 = 0` for none.
     *
     * @param list<string> $values
     */
    private function inLists(QueryBuilder $builder, string $expression, array $values, string $name): string
    {
        $parts = [];
        foreach (array_chunk(array_values($values), self::CHUNK) as $index => $chunk) {
            $parts[] = "{$expression} IN (:{$name}{$index})";
            $builder->setParameter($name . $index, $chunk);
        }

        return $parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
    }

    private function openQuery(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('q', 't', 'b', 'CASE WHEN q.dueOn IS NULL THEN 1 ELSE 0 END AS HIDDEN noDue')
            ->from(TaskQuestion::class, 'q')->join('q.task', 't')->join('t.board', 'b')
            ->where('q.state = :open')
            ->setParameter('open', TaskQuestionState::Open->value)
            ->orderBy('noDue', SortDirection::Ascending)->addOrderBy('q.dueOn', SortDirection::Ascending)->addOrderBy('q.askedAt', SortDirection::Ascending)->addOrderBy('q.id', SortDirection::Ascending)
            ->setMaxResults(self::MY_QUESTIONS_CAP);
    }

    /**
     * Loads the options, recipients and answers of the questions: three queries per {@see self::CHUNK} questions (one
     * fetch join each, so no product of the three).
     *
     * @param list<TaskQuestion> $questions
     */
    private function hydrate(array $questions): void
    {
        foreach (array_chunk(array_map(static fn (TaskQuestion $question): string => $question->getId(), $questions), self::CHUNK) as $ids) {
            foreach (['options', 'recipients', 'answers'] as $collection) {
                $this->entityManager->createQuery('SELECT q, c FROM ' . TaskQuestion::class . ' q LEFT JOIN q.' . $collection . ' c WHERE q.id IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getResult();
            }
        }
    }
}
