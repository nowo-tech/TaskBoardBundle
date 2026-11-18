<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

use function array_chunk;
use function array_filter;
use function array_intersect;
use function array_map;
use function array_values;
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
            ->orderBy('q.answeredAt', 'ASC')->addOrderBy('q.id', 'ASC')
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
            ->setParameter('asker', $id)
            ->getQuery()->getResult();
        $questions = array_values(array_filter($questions, static fn (TaskQuestion $question): bool => ActorId::of($question->getAskedBy()) === $id));
        $this->hydrate($questions);

        return $questions;
    }

    private function openQuery(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('q', 't', 'b', 'CASE WHEN q.dueOn IS NULL THEN 1 ELSE 0 END AS HIDDEN noDue')
            ->from(TaskQuestion::class, 'q')->join('q.task', 't')->join('t.board', 'b')
            ->where('q.state = :open')
            ->setParameter('open', TaskQuestionState::Open->value)
            ->orderBy('noDue', 'ASC')->addOrderBy('q.dueOn', 'ASC')->addOrderBy('q.askedAt', 'ASC')->addOrderBy('q.id', 'ASC')
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
