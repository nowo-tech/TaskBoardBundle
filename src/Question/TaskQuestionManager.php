<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTransaction;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskQuestionAnsweredEvent;
use Nowo\TaskBoardBundle\Event\TaskQuestionAskedEvent;
use Nowo\TaskBoardBundle\Event\TaskQuestionWithdrawnEvent;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionOption;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function array_chunk;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function implode;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function parse_url;
use function preg_match;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PHP_URL_SCHEME;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.enabled`): asks, answers, withdraws questions on tasks and records where
 * a decision was published. Public: the bundle's pages, its Task API and a host (its signed-link page, its MCP tools) call
 * it in process.
 *
 * Every write checks its input and the permission **before** it opens a transaction ({@see TaskQuestionRefusedException},
 * nothing written), then runs in **one** transaction at the connection's current level (a savepoint inside a host's
 * transaction or a transaction-doctrine write unit): the question row is locked (`SELECT … FOR UPDATE`) and its `version`
 * compared with the one the writer read ({@see TaskQuestionConflictException}: nothing written, read it again), the rows,
 * the activity entry and `version + 1` are flushed, then committed. On any failure the transaction is rolled back to the
 * entry level, the events are dropped, a manager the failure closed is reset through the registry and the objects the
 * write touched are reloaded, so a kept-alive worker never sees a closed manager or a value the rollback removed. Events go
 * out after the commit (a failing listener never reports the saved write as failed).
 *
 * Write-unit friendly: everything is re-read by id inside the transaction, so a replay starts from the database. A version
 * conflict is not a lock conflict and is never replayed blindly.
 *
 * Never changes the task's status: no column move, no completion, no archive, no revision bump.
 *
 * Stateless (worker mode, kernel reset off): nothing is kept between calls.
 */
final readonly class TaskQuestionManager
{
    /** The most answers one question keeps (each supersede adds one). */
    public const MAX_ANSWERS = 50;

    /** The longest answer summary of an activity entry. */
    public const SUMMARY_MAX = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $access,
        private TaskQuestionRecipientResolverInterface $recipients,
        private TaskChangeRecorder $recorder,
        private ?ManagerRegistry $managerRegistry = null,
        private ?TaskEventDeferral $events = null,
        private ?TaskQuestionDecisionPublisherInterface $publisher = null,
        private ?WriteProvenanceProviderInterface $provenance = null,
        private ?TaskBoardUsers $users = null,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        private int $maxRecipients = 20,
        private int $maxOpenPerTask = 20,
    ) {
    }

    /**
     * Who `$asker` may ask on `$task` (the ask form's picker): the resolver's candidates with a usable id, each id once.
     *
     * @return list<TaskQuestionRecipientCandidate>
     */
    public function candidates(object $asker, Task $task): array
    {
        $seen       = [];
        $candidates = [];
        foreach ($this->recipients->candidates(Snapshots::task($task), Snapshots::board($task->getBoard()), $asker) as $candidate) {
            if (!$candidate instanceof TaskQuestionRecipientCandidate || !ExactKey::isUsable($candidate->id, TaskQuestionRecipient::SUBJECT_MAX)) {
                $this->logger->warning('TaskBoard: a question recipient candidate with an unusable id was dropped', ['bundle' => 'nowo_task_board', 'task_id' => $task->getId()]);

                continue;
            }
            if (isset($seen[$candidate->id])) {
                continue;
            }
            $seen[$candidate->id] = true;
            $candidates[]         = $candidate;
        }

        return $candidates;
    }

    /**
     * The subject ids `$user` answers for, as the resolver says (unusable ids dropped).
     *
     * @return list<string>
     */
    public function subjectIdsOf(object $user, ?string $tenantKey): array
    {
        return array_values(array_unique(array_filter(
            $this->recipients->subjectIdsOf($user, $tenantKey),
            static fn (mixed $id): bool => is_string($id) && ExactKey::isUsable($id, TaskQuestionRecipient::SUBJECT_MAX),
        )));
    }

    /**
     * @throws TaskQuestionRefusedException `forbidden`, `task_closed`, `invalid_question` (with `field`)
     */
    public function ask(object $asker, Task $task, TaskQuestionInput $input): TaskQuestion
    {
        if ($task->isArchived()) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::TASK_CLOSED, 'task');
        }
        if (!$this->access->canAskQuestion($asker, $task)) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN);
        }
        [$text, $context, $options, $mode, $allowOther, $recipients, $dueOn] = $this->checkedQuestion($asker, $task, $input);
        // max_open_per_task is checked inside the write, under the task's lock (audit TE-L1): a count read here could be stale.

        $question = new TaskQuestion($task, $text, $context, $mode, $allowOther, $asker, $this->clock->now(), $dueOn);
        foreach ($options as $position => $option) {
            $question->addOption(new TaskQuestionOption($question, $position, $option->label, $option->description, $option->recommended));
        }
        foreach ($recipients as $position => $recipient) {
            $question->addRecipient(new TaskQuestionRecipient($question, $recipient->id, Text::cut($recipient->label, TaskQuestionRecipient::LABEL_MAX), $recipient->external, $position));
        }

        $this->write(function () use ($question, $task, $asker, $text): void {
            // Audit TE-L1: the task's row locked, then the open questions counted again under that lock (a concurrent ask
            // waits here), so two asks never pass max_open_per_task together.
            $this->lockTask($task);
            if ($this->lockedOpenCount($task) >= $this->maxOpenPerTask) {
                throw new TaskQuestionRefusedException(TaskQuestionRefusedException::TASK_CLOSED, 'open_questions');
            }
            $this->entityManager->persist($question);
            $this->recorder->record($task, $asker, TaskChangeType::QuestionAsked, null, $text, self::context($question));
        }, $question, true, static fn (): object => new TaskQuestionAskedEvent(TaskQuestionSnapshot::of($question), Snapshots::task($task), Snapshots::board($task->getBoard()), ActorId::of($asker)), TaskBoardEvents::TASK_QUESTION_ASKED);

        return $question;
    }

    /**
     * A signed-in user answers (or supersedes the answer in force, sending the `version` read).
     *
     * @param int|null $version the question's version the answerer read; null: the question must still be open
     * @param string|null $via where the answer comes from (`web`, `app`, `api`, `mcp`); null: the provenance provider's channel, else `web`
     *
     * @throws TaskQuestionRefusedException `withdrawn`, `forbidden`, `invalid_answer` (with `field`)
     * @throws TaskQuestionConflictException the question changed meanwhile
     */
    public function answer(object $user, TaskQuestion $question, TaskQuestionAnswerInput $input, ?int $version = null, ?string $via = null): TaskQuestionAnswer
    {
        if ($question->isWithdrawn()) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::WITHDRAWN);
        }
        $subjects = $this->subjectIdsOf($user, $question->getTask()->getBoard()->getTenantKey());
        if (!$this->access->canAnswerQuestion($user, $question, $subjects)) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN);
        }
        $subject = $subjects[0] ?? 'user:' . (ActorId::of($user) ?? '');
        foreach ($subjects as $candidate) {
            if ($question->recipient($candidate) instanceof TaskQuestionRecipient) {
                $subject = $candidate;

                break;
            }
        }

        return $this->give($question, $input, $version, $subject, $user, null, $via ?? $this->channel());
    }

    /**
     * An external recipient answers through the host's signed link. **The host checks the link first**: the bundle checks
     * only that `$subjectId` is one of the question's recipients and that the question is not withdrawn; no user, tenant or
     * task access is asked.
     *
     * @throws TaskQuestionRefusedException `forbidden` (unknown question or not a recipient), `withdrawn`, `invalid_answer`
     * @throws TaskQuestionConflictException the question changed meanwhile
     */
    public function answerAsSubject(string $questionId, string $subjectId, string $answererLabel, TaskQuestionAnswerInput $input, ?int $version = null, string $via = TaskQuestionAnswer::VIA_EXTERNAL): TaskQuestionAnswer
    {
        $question = $this->find($questionId);
        if (!$question instanceof TaskQuestion || !$question->recipient($subjectId) instanceof TaskQuestionRecipient) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN);
        }
        if ($question->isWithdrawn()) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::WITHDRAWN);
        }
        $label = trim($answererLabel);

        return $this->give($question, $input, $version, $subjectId, null, $label !== '' ? $label : $question->recipient($subjectId)->getLabel(), in_array($via, TaskQuestionAnswer::VIAS, true) ? $via : TaskQuestionAnswer::VIA_EXTERNAL);
    }

    /**
     * @param int|null $version the question's version the user read; null: any
     *
     * @throws TaskQuestionRefusedException `withdrawn`, `forbidden`, `invalid_question` (`reason`)
     * @throws TaskQuestionConflictException the question changed meanwhile
     */
    public function withdraw(object $user, TaskQuestion $question, string $reason, ?int $version = null): void
    {
        if ($question->isWithdrawn()) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::WITHDRAWN);
        }
        if (!$this->access->canWithdrawQuestion($user, $question)) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN);
        }
        $reason = self::plain($reason);
        if ($reason === '' || mb_strlen($reason) > TaskQuestion::REASON_MAX) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::INVALID_QUESTION, 'reason');
        }
        $task = $question->getTask();
        $this->write(function () use ($question, $task, $user, $reason, $version): void {
            $this->lock($question, $version, false);
            $question->withdraw($user, $reason, $this->clock->now());
            $this->recorder->record($task, $user, TaskChangeType::QuestionWithdrawn, null, $reason, self::context($question));
        }, $question, false, static fn (): object => new TaskQuestionWithdrawnEvent(TaskQuestionSnapshot::of($question), Snapshots::task($task), Snapshots::board($task->getBoard()), ActorId::of($user)), TaskBoardEvents::TASK_QUESTION_WITHDRAWN);
    }

    /** The label of the *Publish decision* button for this user, or null (no publisher, not allowed, hidden by the host). */
    public function publishLabel(object $user, TaskQuestion $question): ?string
    {
        if (!$this->publisher instanceof TaskQuestionDecisionPublisherInterface || !$this->access->canPublishDecision($user, $question)) {
            return null;
        }
        $label = $this->publisher->targetLabel(TaskQuestionSnapshot::of($question), $user);
        $label = $label === null ? '' : trim($label);

        return $label === '' ? null : Text::cut($label, TaskQuestion::PUBLISHED_LABEL_MAX);
    }

    /**
     * Publishes the decision through the host's publisher (outside the transaction), then stores where (version-checked).
     *
     * @throws TaskQuestionRefusedException `forbidden` (no publisher, not answered, not allowed), `invalid_question` (`published_url`)
     * @throws TaskQuestionConflictException the question changed while the host published
     */
    public function publish(object $user, TaskQuestion $question, ?int $version = null): TaskQuestionPublication
    {
        $label   = $this->publishLabel($user, $question);
        $current = $question->getCurrentAnswer();
        if ($label === null || !$this->publisher instanceof TaskQuestionDecisionPublisherInterface || !$current instanceof TaskQuestionAnswer) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN);
        }
        $seen = $version ?? $question->getVersion();
        if ($seen !== $question->getVersion()) {
            throw new TaskQuestionConflictException($question->getVersion());
        }
        $publication = $this->publisher->publish(TaskQuestionSnapshot::of($question), TaskQuestionAnswerSnapshot::of($current), $user);
        $url         = trim($publication->url);
        if (strlen($url) > TaskQuestion::PUBLISHED_URL_MAX || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::INVALID_QUESTION, 'published_url');
        }
        $shown = Text::cut(trim($publication->label) !== '' ? trim($publication->label) : $label, TaskQuestion::PUBLISHED_LABEL_MAX);
        $this->write(function () use ($question, $seen, $url, $shown): void {
            $this->lock($question, $seen, false);
            $question->published($url, $shown, $this->clock->now());
        }, $question, false);

        return new TaskQuestionPublication($url, $shown);
    }

    public function find(string $questionId): ?TaskQuestion
    {
        if ($questionId === '') {
            return null;
        }
        $question = $this->entityManager->find(TaskQuestion::class, $questionId);

        // REQ-SEC-006: the id read back is compared exactly (a case-insensitive collation could match a look-alike).
        return $question instanceof TaskQuestion && $question->getId() === $questionId ? $question : null;
    }

    /**
     * The question `$questionId` of `$task` (byte for byte, on that task only), or null.
     */
    public function findOnTask(Task $task, string $questionId): ?TaskQuestion
    {
        $question = $this->find($questionId);

        return $question instanceof TaskQuestion && $question->getTask()->getId() === $task->getId() ? $question : null;
    }

    /**
     * Since 2.23.0 (erasure): call before deleting a user. Their questions, answers and withdrawals stay; the rows stop
     * pointing at them, and with `$forgetName` their name goes too.
     *
     * @return int the rows changed
     */
    public function detachUser(object $user, bool $forgetName = false): int
    {
        $userId = ActorId::of($user);
        if ($userId === null) {
            return 0;
        }
        $changed = 0;
        foreach ([[TaskQuestion::class, 'askedBy', 'askedByName'], [TaskQuestion::class, 'withdrawnBy', 'withdrawnByName'], [TaskQuestionAnswer::class, 'answeredBy', 'answeredByName']] as [$class, $field, $name]) {
            // Audit TE-L2 (OC-M2, REQ-SEC-006): in the id mode the rows of exactly this id, by primary key (the database's
            // collation may fold case or trailing spaces); with a relation, the user object itself.
            [$where, $batches] = $this->users instanceof TaskBoardUsers && $this->users->idMode()
                ? ['r.id IN (:match)', array_chunk(TaskBoardUsers::exactRowIds($this->entityManager, $class, $field, TaskBoardUsers::exactId($user)), ExactKey::CHUNK)]
                : ['r.' . $field . ' = :match', [$user]];
            foreach ($batches as $match) {
                $builder = $this->entityManager->createQueryBuilder()->update($class, 'r')->set('r.' . $field, ':none');
                if ($forgetName) {
                    $builder->set('r.' . $name, ':none');
                }
                $changed += (int) $builder->where($where)
                    ->setParameter('none', null)
                    ->setParameter('match', $match)
                    ->getQuery()
                    ->execute();
            }
        }
        if ($forgetName) {
            // Audit TE-L2: the user's own subject ids (the default `user:<id>` and what the resolver says) stop naming them
            // on the recipient rows and on the answers given for them.
            foreach (array_unique([DefaultTaskQuestionRecipientResolver::PREFIX . $userId, ...$this->subjectIdsOf($user, null)]) as $subjectId) {
                $changed += $this->forgetSubject($subjectId);
            }
        }

        return $changed;
    }

    /**
     * Since 2.23.0 (erasure of an external contact, Q-B-14): the subject becomes `forgotten:<hash>` everywhere the bundle
     * keeps it — the recipient rows (subject id and label), the answers given for it (`answered_by_subject`, and the name of
     * an answer without a user), and the name of the activity entries those answers wrote. The answers' content stays (it
     * is the decision). Idempotent: a second call finds nothing.
     *
     * @return int the rows changed
     */
    public function forgetSubject(string $subjectId): int
    {
        if (!ExactKey::isUsable($subjectId, TaskQuestionRecipient::SUBJECT_MAX)) {
            return 0;
        }
        $forgotten = self::forgottenSubject($subjectId);
        /** @var list<array{question: string, name: string}> $answered */
        $answered = $this->entityManager->createQuery('SELECT IDENTITY(a.question) AS question, a.answeredByName AS name FROM ' . TaskQuestionAnswer::class . ' a WHERE a.answeredBySubject = :subject AND a.answeredBy IS NULL AND a.answeredByName IS NOT NULL')
            ->setParameter('subject', $subjectId)
            ->getArrayResult();
        $changed = 0;
        foreach ($answered as $row) {
            $changed += (int) $this->entityManager->createQueryBuilder()->update(TaskChangeHistory::class, 'h')
                ->set('h.userName', ':forgotten')
                ->where('h.user IS NULL AND h.context = :context AND h.userName = :name')
                ->setParameter('forgotten', $forgotten)->setParameter('context', 'question:' . $row['question'])->setParameter('name', $row['name'])
                ->getQuery()->execute();
        }
        $changed += (int) $this->entityManager->createQueryBuilder()->update(TaskQuestionAnswer::class, 'a')
            ->set('a.answeredByName', ':none')->where('a.answeredBy IS NULL AND a.answeredBySubject = :subject')
            ->setParameter('none', null)->setParameter('subject', $subjectId)
            ->getQuery()->execute();
        $changed += (int) $this->entityManager->createQueryBuilder()->update(TaskQuestionAnswer::class, 'a')
            ->set('a.answeredBySubject', ':forgotten')->where('a.answeredBySubject = :subject')
            ->setParameter('forgotten', $forgotten)->setParameter('subject', $subjectId)
            ->getQuery()->execute();

        return $changed + (int) $this->entityManager->createQueryBuilder()->update(TaskQuestionRecipient::class, 'r')
            ->set('r.label', ':forgotten')->set('r.subjectId', ':forgotten')->where('r.subjectId = :subject')
            ->setParameter('forgotten', $forgotten)->setParameter('subject', $subjectId)
            ->getQuery()->execute();
    }

    /** The id an erased subject is kept under: `forgotten:` and the first 16 hex characters of its SHA-256. */
    public static function forgottenSubject(string $subjectId): string
    {
        return 'forgotten:' . substr(hash('sha256', $subjectId), 0, 16);
    }

    /** The open questions of the task. */
    public function openCount(Task $task): int
    {
        return (int) $this->entityManager->createQuery('SELECT COUNT(q.id) FROM ' . TaskQuestion::class . ' q WHERE q.task = :task AND q.state = :open')
            ->setParameter('task', $task->getId())
            ->setParameter('open', TaskQuestionState::Open->value)
            ->getSingleScalarResult();
    }

    /**
     * A one-line summary of an answer for the activity entry: the options picked, then the Other text.
     */
    public static function summary(TaskQuestionAnswer $answer): string
    {
        $parts = array_map(static fn (TaskQuestionOption $option): string => $option->getLabel(), $answer->getSelectedOptions());
        if ($answer->getOtherText() !== null) {
            $parts[] = $answer->getOtherText();
        }

        return Text::cut(str_replace("\n", ' ', implode(' · ', $parts)), self::SUMMARY_MAX);
    }

    private static function context(TaskQuestion $question): string
    {
        return 'question:' . $question->getId();
    }

    private function give(TaskQuestion $question, TaskQuestionAnswerInput $input, ?int $version, string $subject, ?object $user, ?string $name, string $via): TaskQuestionAnswer
    {
        [$optionIds, $other] = $this->checkedAnswer($question, $input);
        // MAX_ANSWERS is checked inside the write, under the question's lock (audit TE-L1).
        $task = $question->getTask();
        /** @var array{0: TaskQuestionAnswer, 1: TaskQuestionAnswer|null} $given */
        $given = $this->write(function () use ($question, $version, $optionIds, $other, $subject, $user, $name, $via, $task): array {
            $this->lock($question, $version, true);
            // Audit TE-L1: the answers counted again under the question's lock (every answer takes it first).
            if ($this->lockedAnswerCount($question) >= self::MAX_ANSWERS) {
                throw new TaskQuestionRefusedException(TaskQuestionRefusedException::INVALID_ANSWER, 'answers');
            }
            // Checked again on the row as locked: the options are immutable, the Other switch too.
            $this->checkedAnswer($question, new TaskQuestionAnswerInput($optionIds, $other));
            $superseded = $question->getCurrentAnswer();
            $answer     = new TaskQuestionAnswer($question, $optionIds, $other, $subject, $user, $user === null ? $name : UserLabel::of($user), $via, $this->clock->now());
            $question->answer($answer);
            $this->recorder->recordAs($task, $user, $name, TaskChangeType::QuestionAnswered, $superseded instanceof TaskQuestionAnswer ? self::summary($superseded) : null, self::summary($answer), self::context($question));

            return [$answer, $superseded];
        }, $question, false, static fn (array $given): object => new TaskQuestionAnsweredEvent(
            TaskQuestionSnapshot::of($question),
            Snapshots::task($task),
            Snapshots::board($task->getBoard()),
            ActorId::of($user),
            TaskQuestionAnswerSnapshot::of($given[0]),
            $given[1] instanceof TaskQuestionAnswer ? TaskQuestionAnswerSnapshot::of($given[1]) : null,
        ), TaskBoardEvents::TASK_QUESTION_ANSWERED);

        return $given[0];
    }

    /** Locks the task's row until the transaction ends (asks on one task wait for each other). */
    private function lockTask(Task $task): void
    {
        $this->entityManager->createQuery('SELECT t.id FROM ' . Task::class . ' t WHERE t.id = :id')
            ->setParameter('id', $task->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult();
    }

    /**
     * The open questions of the task as committed: a locking read, which sees the latest committed rows even inside a
     * REPEATABLE READ transaction (MySQL / MariaDB), counted in PHP (PostgreSQL refuses FOR UPDATE with an aggregate).
     */
    private function lockedOpenCount(Task $task): int
    {
        return count($this->entityManager->createQuery('SELECT q.id FROM ' . TaskQuestion::class . ' q WHERE q.task = :task AND q.state = :open')
            ->setParameter('task', $task->getId())
            ->setParameter('open', TaskQuestionState::Open->value)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult());
    }

    /** The answers of the question as committed (a locking read, as {@see self::lockedOpenCount()}). */
    private function lockedAnswerCount(TaskQuestion $question): int
    {
        return count($this->entityManager->createQuery('SELECT a.id FROM ' . TaskQuestionAnswer::class . ' a WHERE a.question = :question')
            ->setParameter('question', $question->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getScalarResult());
    }

    /**
     * Locks the question's row until the transaction ends, reloads it when another writer changed it, then compares the
     * version (`$expected` null: any — or, for an answer, the question must still be open).
     */
    private function lock(TaskQuestion $question, ?int $expected, bool $answering): void
    {
        // Audit TE-2: the task first, as ask() does (fixed order task -> question), so an ask and an answer never deadlock.
        $this->lockTask($question->getTask());
        /** @var array{version: int|string, state: string|TaskQuestionState}|null $row */
        $row = $this->entityManager->createQuery('SELECT q.version AS version, q.state AS state FROM ' . TaskQuestion::class . ' q WHERE q.id = :id')
            ->setParameter('id', $question->getId())
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        if ($row === null) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::FORBIDDEN);
        }
        $locked = (int) $row['version'];
        if ($locked !== $question->getVersion()) {
            $this->entityManager->refresh($question);
        }
        // Audit TE: inside an outer REPEATABLE READ transaction (MySQL / MariaDB) the refresh reads the transaction's
        // snapshot, not the row just locked: never write from it.
        if ($question->getVersion() !== $locked) {
            $state = $row['state'] instanceof TaskQuestionState ? $row['state'] : TaskQuestionState::from((string) $row['state']);
            if ($state === TaskQuestionState::Withdrawn) {
                throw new TaskQuestionRefusedException(TaskQuestionRefusedException::WITHDRAWN);
            }
            throw new TaskQuestionConflictException($locked);
        }
        $current = $question->getVersion();
        if ($question->isWithdrawn()) {
            throw new TaskQuestionRefusedException(TaskQuestionRefusedException::WITHDRAWN);
        }
        if ($expected !== null ? $expected !== $current : $answering && !$question->isOpen()) {
            throw new TaskQuestionConflictException($current);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $change
     * @param (callable(T): object)|null $event built after the commit, from what `$change` returned
     *
     * @return T
     */
    private function write(callable $change, TaskQuestion $question, bool $new, ?callable $event = null, ?string $eventName = null): mixed
    {
        $known = array_map(static fn (TaskQuestionAnswer $answer): string => $answer->getId(), $question->getAnswers());
        // One TaskApiTransaction (the Task API's write transaction, built per call: stateless): a savepoint inside a host's
        // transaction or a write unit; the events are held until the outermost commit and dropped on a rollback.
        $transaction = new TaskApiTransaction($this->entityManager, $this->managerRegistry, $this->events);
        try {
            return $transaction->run(function () use ($change, $event, $eventName): mixed {
                $result = $change();
                RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
                if ($event !== null && $eventName !== null && $this->events instanceof TaskEventDeferral) {
                    // Held by the deferral of run() and dispatched after the commit (a failing listener is logged).
                    $this->events->dispatchSaved($event($result), $eventName);
                }

                return $result;
            }, [$question->getTask()]);
        } catch (Throwable $failure) {
            $this->forget($question, $new, $known);

            throw $failure;
        }
    }

    /**
     * After a rollback: nothing of the failed write stays in the manager (best effort) — a new question and its rows, or
     * an answer added to `$question` (not one of `$known`), are detached; the question and its task are read again.
     *
     * @param list<string> $known the question's answers before the write
     */
    private function forget(TaskQuestion $question, bool $new, array $known): void
    {
        // A manager resetIfClosed() replaced has none of these entities: contains() is false and nothing is touched.
        try {
            $task  = $question->getTask();
            $added = $new ? [...$question->getOptions(), ...$question->getRecipients(), $question] : array_filter($question->getAnswers(), static fn (TaskQuestionAnswer $answer): bool => !in_array($answer->getId(), $known, true));
            foreach ($added as $row) {
                if ($this->entityManager->contains($row)) {
                    $this->entityManager->detach($row);
                }
            }
            if (!$new && $this->entityManager->contains($question)) {
                $this->entityManager->refresh($question);
            }
            if ($this->entityManager->contains($task)) {
                $this->entityManager->refresh($task);
            }
        } catch (Throwable) {
            // Best effort: a row the rollback removed, or a lost connection, leaves the next read to the database.
        }
    }

    private function channel(): string
    {
        $provenance = $this->provenance?->current();

        return $provenance instanceof WriteProvenance ? $provenance->channel->value : 'web';
    }

    /**
     * @return array{0: string, 1: ?string, 2: list<TaskQuestionOptionInput>, 3: TaskQuestionChoiceMode, 4: bool, 5: list<TaskQuestionRecipientCandidate>, 6: ?DateTimeImmutable}
     */
    private function checkedQuestion(object $asker, Task $task, TaskQuestionInput $input): array
    {
        $invalid = static fn (string $field): TaskQuestionRefusedException => new TaskQuestionRefusedException(TaskQuestionRefusedException::INVALID_QUESTION, $field);
        $text    = self::plain($input->text);
        if ($text === '' || mb_strlen($text) > TaskQuestion::TEXT_MAX) {
            throw $invalid('text');
        }
        $context = $input->context === null ? '' : trim(str_replace("\r\n", "\n", $input->context));
        if (mb_strlen($context) > TaskQuestion::CONTEXT_MAX) {
            throw $invalid('context');
        }

        $options = [];
        $labels  = [];
        $starred = 0;
        foreach ($input->options as $option) {
            $label       = self::plain($option->label);
            $description = self::plain((string) $option->description);
            if ($label === '' || mb_strlen($label) > TaskQuestionOption::LABEL_MAX || mb_strlen($description) > TaskQuestionOption::DESCRIPTION_MAX || in_array(mb_strtolower($label), $labels, true)) {
                throw $invalid('options');
            }
            $labels[] = mb_strtolower($label);
            $starred += $option->recommended ? 1 : 0;
            $options[] = new TaskQuestionOptionInput($label, $description === '' ? null : $description, $option->recommended);
        }
        if ((count($options) !== 0 && (count($options) < 2 || count($options) > 4)) || $starred > 1) {
            throw $invalid('options');
        }
        if ($options === [] && $input->choiceMode === TaskQuestionChoiceMode::Multiple) {
            throw $invalid('choice');
        }

        $wanted = array_values(array_unique($input->recipients));
        if ($wanted === [] || count($wanted) > $this->maxRecipients || count($wanted) !== count($input->recipients)) {
            throw $invalid('recipients');
        }
        $byId = [];
        foreach ($this->candidates($asker, $task) as $candidate) {
            $byId[$candidate->id] = $candidate;
        }
        $recipients = [];
        foreach ($wanted as $id) {
            if (!is_string($id) || !isset($byId[$id]) || $byId[$id]->id !== $id) {
                throw $invalid('recipients');
            }
            $recipients[] = $byId[$id];
        }

        $dueOn = $input->dueOn?->setTime(0, 0);
        if ($dueOn instanceof DateTimeImmutable && $dueOn->format('Y-m-d') < $this->clock->now()->format('Y-m-d')) {
            throw $invalid('due_date');
        }

        return [$text, $context === '' ? null : $context, $options, $input->choiceMode, $options === [] || $input->allowOther, $recipients, $dueOn];
    }

    /**
     * @return array{0: list<string>, 1: ?string}
     */
    private function checkedAnswer(TaskQuestion $question, TaskQuestionAnswerInput $input): array
    {
        $invalid = static fn (string $field): TaskQuestionRefusedException => new TaskQuestionRefusedException(TaskQuestionRefusedException::INVALID_ANSWER, $field);
        $picked  = [];
        foreach ($input->optionIds as $id) {
            if (!is_string($id) || !$question->option($id) instanceof TaskQuestionOption || in_array($id, $picked, true)) {
                throw $invalid('options');
            }
            $picked[] = $id;
        }
        $other = $input->otherText === null ? '' : trim(str_replace("\r\n", "\n", $input->otherText));
        if (mb_strlen($other) > TaskQuestionAnswer::OTHER_MAX || ($other !== '' && !$question->allowsOther())) {
            throw $invalid('other_text');
        }
        if ($picked === [] && $other === '') {
            throw $invalid('options');
        }
        if (!$question->isMultiple() && (count($picked) > 1 || ($picked !== [] && $other !== ''))) {
            throw $invalid(count($picked) > 1 ? 'options' : 'other_text');
        }
        // In the question's order, whatever the order sent.
        $ordered = [];
        foreach ($question->getOptions() as $option) {
            if (in_array($option->getId(), $picked, true)) {
                $ordered[] = $option->getId();
            }
        }

        return [$ordered, $other === '' ? null : $other];
    }

    /** One line of plain text: trimmed, line breaks and tabs as spaces. */
    private static function plain(string $text): string
    {
        return trim(str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text));
    }
}
