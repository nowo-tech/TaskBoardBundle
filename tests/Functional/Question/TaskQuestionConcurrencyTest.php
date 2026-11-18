<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Closure;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTransaction;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionConflictException;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Audit TE (REQ-RUNTIME-003): the question writes and the locked task revision against a second writer.
 *
 * The two-connection tests run on a real server (MySQL, MariaDB, PostgreSQL; `TASK_BOARD_TEST_DATABASE_URL`): "B" works
 * inside an outer transaction that read the question (or task) before "A" committed — a host transaction, a Messenger
 * handler with the doctrine_transaction middleware, an MCP tool. Under REPEATABLE READ (MySQL / MariaDB) B's refresh reads
 * its snapshot, not the row it just locked: B must never write from it (TE-M1). On SQLite (one in-memory connection) the
 * same stale refresh is simulated by a postLoad listener that puts the old value back.
 */
final class TaskQuestionConcurrencyTest extends QuestionTestCase
{
    public static ?int $staleVersion = null;

    private AppUser $carol;

    protected function setUp(): void
    {
        self::$taskBoardConfig = ['task_questions' => ['enabled' => true, 'max_open_per_task' => 2]];
        parent::setUp();
        self::$staleVersion = null;
        $this->carol        = $this->user('carol@example.com');
        $this->task->addMember(new TaskMember($this->task, $this->carol, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->em->refresh($this->roadmap);
    }

    public function testTwoFirstAnswersInsideAnOuterTransaction(): void
    {
        [$outcome, $answers] = $this->race(
            fn (TaskQuestion $q) => $this->manager()->answer($this->carol, $q, new TaskQuestionAnswerInput([$q->getOptions()[0]->getId()])),
            static fn (TaskQuestionManager $m, TaskQuestion $qB, AppUser $bobB) => $m->answer($bobB, $qB, new TaskQuestionAnswerInput([$qB->getOptions()[1]->getId()])),
        );
        self::assertSame('conflict(2)', $outcome, 'the second first answer loses');
        self::assertSame(1, $answers);
    }

    public function testAStaleVersionInsideAnOuterTransaction(): void
    {
        [$outcome, $answers] = $this->race(
            fn (TaskQuestion $q) => $this->manager()->answer($this->carol, $q, new TaskQuestionAnswerInput([$q->getOptions()[0]->getId()])),
            static fn (TaskQuestionManager $m, TaskQuestion $qB, AppUser $bobB) => $m->answer($bobB, $qB, new TaskQuestionAnswerInput([$qB->getOptions()[1]->getId()]), 1),
        );
        self::assertSame('conflict(2)', $outcome);
        self::assertSame(1, $answers);
    }

    public function testAnAnswerAfterAWithdrawInsideAnOuterTransaction(): void
    {
        [$outcome, $answers, $state] = $this->race(
            fn (TaskQuestion $q) => $this->manager()->withdraw($this->owner, $q, 'Not needed'),
            static fn (TaskQuestionManager $m, TaskQuestion $qB, AppUser $bobB) => $m->answer($bobB, $qB, new TaskQuestionAnswerInput([$qB->getOptions()[1]->getId()])),
        );
        self::assertSame('refused(withdrawn)', $outcome);
        self::assertSame(0, $answers);
        self::assertSame('withdrawn', $state);
    }

    /** TE-L1: two asks on one task never pass max_open_per_task together (the second recounts under the task's lock). */
    public function testTheOpenPerTaskCapHoldsAgainstAConcurrentAsk(): void
    {
        $this->needsAServer();
        $this->two();
        [$em2, $second] = $this->second();
        $em2->getConnection()->beginTransaction();
        $taskB  = $em2->find(Task::class, $this->task->getId());
        $ownerB = $em2->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(Task::class, $taskB);
        self::assertInstanceOf(AppUser::class, $ownerB);
        // B read one open question inside its transaction; A asks and commits: two open, the cap.
        self::assertSame(1, $second->openCount($taskB));
        $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput(text: 'A asks', recipients: [$this->subject($this->bob)]));
        $outcome = 'accepted';
        try {
            $second->ask($ownerB, $taskB, new TaskQuestionInput(text: 'B asks', recipients: [$this->subject($this->bob)]));
        } catch (TaskQuestionRefusedException $refused) {
            $outcome = 'refused(' . $refused->reason . ':' . $refused->field . ')';
        }
        if ($em2->getConnection()->isTransactionActive()) {
            $em2->getConnection()->commit();
        }
        $em2->getConnection()->close();
        self::assertSame('refused(task_closed:open_questions)', $outcome);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM task_board_task_questions WHERE task_id = ? AND state = 'open'", [$this->task->getId()]));
    }

    /** TE-M1 for TaskApiTransaction::lockedRevision(): a stale copy of the task is never returned as locked. */
    public function testALockedRevisionNeverComesFromAStaleSnapshot(): void
    {
        $this->needsAServer();
        [$em2] = $this->second();
        $em2->getConnection()->beginTransaction();
        $taskB = $em2->find(Task::class, $this->task->getId());
        self::assertInstanceOf(Task::class, $taskB);
        $before = $taskB->getRevision();
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET revision = revision + 1, title = ? WHERE id = ?', ['Changed by A', $this->task->getId()]);
        $transaction = new TaskApiTransaction($em2);
        try {
            $locked = $transaction->run(static fn (): int => $transaction->lockedRevision($taskB));
            // PostgreSQL (READ COMMITTED): the refresh read the committed row.
            self::assertSame($before + 1, $locked);
            self::assertSame($locked, $taskB->getRevision());
            self::assertSame('Changed by A', $taskB->getTitle());
        } catch (TaskApiRefusal $stale) {
            // MySQL / MariaDB (REPEATABLE READ): 409 TASK_409 with the locked revision, nothing written.
            self::assertSame(409, $stale->status);
            self::assertSame(['current_revision' => $before + 1], $stale->extra);
        } finally {
            if ($em2->getConnection()->isTransactionActive()) {
                $em2->getConnection()->rollBack();
            }
            $em2->getConnection()->close();
        }
    }

    /** The stale refresh of an outer REPEATABLE READ transaction, simulated (any database): a conflict, nothing written. */
    public function testAStaleRefreshOfTheQuestionNeverWrites(): void
    {
        $question = $this->two();
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_questions SET version = 2 WHERE id = ?', [$question->getId()]);
        $this->staleRefreshes();
        self::$staleVersion = 1;
        try {
            $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]), 1);
            self::fail('Answered from a stale copy');
        } catch (TaskQuestionConflictException $conflict) {
            self::assertSame(2, $conflict->currentVersion);
        }
        self::$staleVersion = null;
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_task_question_answers WHERE question_id = ?', [$question->getId()]));
    }

    public function testAStaleRefreshOfAWithdrawnQuestionIsRefusedAsWithdrawn(): void
    {
        $question = $this->two();
        $this->em->getConnection()->executeStatement("UPDATE task_board_task_questions SET version = 2, state = 'withdrawn' WHERE id = ?", [$question->getId()]);
        $this->staleRefreshes();
        self::$staleVersion = 1;
        try {
            $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
            self::fail('Answered a withdrawn question from a stale copy');
        } catch (TaskQuestionRefusedException $refused) {
            self::assertSame(TaskQuestionRefusedException::WITHDRAWN, $refused->reason);
        }
        self::$staleVersion = null;
    }

    public function testAStaleRefreshOfTheTaskIsAConflict(): void
    {
        $task = $this->task;
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET revision = revision + 1 WHERE id = ?', [$task->getId()]);
        $old = $task->getRevision();
        $this->em->getEventManager()->addEventListener(Events::postLoad, new class($old) {
            public function __construct(private int $old)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($args->getObject() instanceof Task) {
                    (new ReflectionProperty(Task::class, 'revision'))->setValue($args->getObject(), $this->old);
                }
            }
        });
        $transaction = new TaskApiTransaction($this->em);
        try {
            $transaction->run(static fn (): int => $transaction->lockedRevision($task));
            self::fail('A stale task was returned as locked');
        } catch (TaskApiRefusal $stale) {
            self::assertSame(['current_revision' => $old + 1], $stale->extra);
        }
    }

    /**
     * @param Closure(TaskQuestion): mixed $a A's write, committed on the first connection while B's transaction is open
     * @param Closure(TaskQuestionManager, TaskQuestion, AppUser): mixed $b B's write, on the second connection
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function race(Closure $a, Closure $b): array
    {
        $this->needsAServer();
        $q              = $this->two();
        [$em2, $second] = $this->second();
        $em2->getConnection()->beginTransaction();
        $qB = $em2->find(TaskQuestion::class, $q->getId());
        self::assertInstanceOf(TaskQuestion::class, $qB);
        $qB->getAnswers();
        $qB->getRecipients();
        $bobB = $em2->find(AppUser::class, $this->bob->getId());
        self::assertInstanceOf(AppUser::class, $bobB);
        $a($q);
        $outcome = 'accepted';
        try {
            $b($second, $qB, $bobB);
        } catch (TaskQuestionConflictException $conflict) {
            $outcome = 'conflict(' . $conflict->currentVersion . ')';
        } catch (TaskQuestionRefusedException $refused) {
            $outcome = 'refused(' . $refused->reason . ')';
        } catch (Throwable $failure) {
            $outcome = 'error(' . $failure::class . ')';
        }
        if ($em2->getConnection()->isTransactionActive()) {
            $em2->getConnection()->commit();
        }
        $em2->getConnection()->close();
        $connection = $this->em->getConnection();

        return [
            $outcome,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_task_question_answers WHERE question_id = ?', [$q->getId()]),
            (string) $connection->fetchOne('SELECT state FROM task_board_task_questions WHERE id = ?', [$q->getId()]),
        ];
    }

    private function needsAServer(): void
    {
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Two connections need a database server (TASK_BOARD_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL).');
        }
    }

    /** A second entity manager on its own connection, and the bundle's question manager bound to it. */
    private function second(): array
    {
        $connection = DriverManager::getConnection($this->em->getConnection()->getParams(), $this->em->getConnection()->getConfiguration());
        $em2        = new EntityManager($connection, $this->em->getConfiguration(), $this->em->getEventManager());
        $original   = $this->manager();
        $reflection = new ReflectionClass(TaskQuestionManager::class);
        $copy       = $reflection->newInstanceWithoutConstructor();
        $values     = [];
        foreach ($reflection->getProperties() as $property) {
            $values[$property->getName()] = $property->getValue($original);
        }
        $values['entityManager']   = $em2;
        $values['managerRegistry'] = null;
        Closure::bind(function (array $values): void {
            foreach ($values as $name => $value) {
                $this->{$name} = $value;
            }
        }, $copy, TaskQuestionManager::class)($values);

        return [$em2, $copy];
    }

    private function two(): TaskQuestion
    {
        return $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput(
            text: 'Which hosting?',
            recipients: [$this->subject($this->bob), $this->subject($this->carol)],
            options: [new TaskQuestionOptionInput('Hetzner'), new TaskQuestionOptionInput('AWS')],
        ));
    }

    /** Every refresh of a question puts {@see self::$staleVersion} back, as a REPEATABLE READ snapshot would read it. */
    private function staleRefreshes(): void
    {
        $this->em->getEventManager()->addEventListener(Events::postLoad, new class {
            public function postLoad(PostLoadEventArgs $args): void
            {
                $object = $args->getObject();
                if ($object instanceof TaskQuestion && TaskQuestionConcurrencyTest::$staleVersion !== null) {
                    (new ReflectionProperty(TaskQuestion::class, 'version'))->setValue($object, TaskQuestionConcurrencyTest::$staleVersion);
                }
            }
        });
    }
}
