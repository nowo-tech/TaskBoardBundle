<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Question\DefaultTaskQuestionRecipientResolver;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\SwitchableWriteProvenance;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function str_replace;

/**
 * Beacon spec 242: the edges — a row another writer changed or removed under the lock, a failed write of a new question,
 * the host's TASK_ACCESS_CHECK decisions on question actions, the answers cap, free-text answers and the reads.
 */
final class TaskQuestionEdgeTest extends QuestionTestCase
{
    public function testTheLockedRowIsReadAgain(): void
    {
        $question   = $this->ask();
        $option     = $question->getOptions()[0]->getId();
        $connection = $this->em->getConnection();

        // Another writer bumped the version (still open): read again, then answered.
        $connection->executeStatement('UPDATE task_board_task_questions SET version = version + 1 WHERE id = ?', [$question->getId()]);
        $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$option]));
        self::assertSame(3, $question->getVersion());

        // Another writer withdrew it meanwhile.
        $connection->executeStatement("UPDATE task_board_task_questions SET state = 'withdrawn', version = version + 1 WHERE id = ?", [$question->getId()]);
        $this->expectRefusal(TaskQuestionRefusedException::WITHDRAWN, fn (): TaskQuestionAnswer => $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$option]), 3));

        // Another writer removed it. (Read the task again: with the private entity manager a rolled-back write drops it.)
        $task = $this->em->find(Task::class, $this->task->getId());
        self::assertInstanceOf(Task::class, $task);
        $second = $this->ask($task);
        $connection->executeStatement('DELETE FROM task_board_task_question_options WHERE question_id = ?', [$second->getId()]);
        $connection->executeStatement('DELETE FROM task_board_task_question_recipients WHERE question_id = ?', [$second->getId()]);
        $connection->executeStatement('DELETE FROM task_board_task_questions WHERE id = ?', [$second->getId()]);
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn (): TaskQuestionAnswer => $this->manager()->answer($this->bob, $second, new TaskQuestionAnswerInput([$second->getOptions()[0]->getId()])));
    }

    public function testAFailedAskLeavesNoRowInTheManager(): void
    {
        self::$taskBoardConfig = ['task_questions' => ['enabled' => true], 'write_provenance_provider' => 'app.switchable_provenance'];
        self::ensureKernelShutdown();
        $this->setUp();
        SwitchableWriteProvenance::$fail = true;
        try {
            $this->ask();
            self::fail('Asked without a provenance');
        } catch (RuntimeException $failure) {
            self::assertSame('Provenance unavailable', $failure->getMessage());
        } finally {
            SwitchableWriteProvenance::$fail = false;
        }
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertSame([], $this->em->getRepository(TaskQuestion::class)->findAll());
        // The manager stays usable: the next ask works.
        $this->ask();
        self::assertCount(1, $this->em->getRepository(TaskQuestion::class)->findAll());
    }

    public function testAFailedFlushResetsTheManager(): void
    {
        $this->ask();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP TABLE task_board_task_question_options');
        try {
            $this->ask();
            self::fail('Asked without the options table');
        } catch (Throwable) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testAFailedLockReadsNothingBack(): void
    {
        $question   = $this->ask();
        $connection = $this->em->getConnection();
        // The question's own table is gone: the lock fails, and reading it again fails too (best effort).
        $connection->executeStatement('DROP TABLE task_board_task_question_answers');
        $connection->executeStatement('DROP TABLE task_board_task_question_options');
        $connection->executeStatement('DROP TABLE task_board_task_question_recipients');
        $connection->executeStatement('DROP TABLE task_board_task_questions');
        try {
            $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
            self::fail('Answered without the table');
        } catch (Throwable $failure) {
            self::assertNotInstanceOf(TaskQuestionRefusedException::class, $failure);
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    /**
     * Audit TE-M2: a host listener written before 2.23 that grants every action on TASK_ACCESS_CHECK (Beacon's
     * ProjectTaskBoardAccess shape: all but track_time, read-only unless the user manages) never widens the question rules.
     */
    public function testAGenericGrantNeverWidensTheQuestionRules(): void
    {
        $question   = $this->ask();
        $dave       = $this->user('dave@example.com');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($dave): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                return;
            }
            $event->grant();
            if ($event->getSubject() !== $dave) {
                $event->markReadOnly();
            }
        });
        // Dave sees the task (granted), but is no recipient, not the asker and no manager of the board.
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn (): TaskQuestionAnswer => $this->manager()->answer($dave, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()])));
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->withdraw($dave, $question, 'Dave says no'));
        $this->em->refresh($question);
        self::assertTrue($question->isOpen());
        self::assertSame([], $question->getAnswers());
        // A generic deny still refuses: the recipient may not answer.
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_ANSWER_QUESTION) {
                $event->deny();
            }
        });
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn (): TaskQuestionAnswer => $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()])));
    }

    public function testTheHostDecidesQuestionActionsThroughTaskQuestionAccessCheck(): void
    {
        $question   = $this->ask();
        $seen       = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_QUESTION_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use (&$seen): void {
            match ($event->getAction()) {
                TaskAccessCheckEvent::ACTION_ANSWER_QUESTION   => $event->grant(),
                TaskAccessCheckEvent::ACTION_WITHDRAW_QUESTION => $event->deny(),
                TaskAccessCheckEvent::ACTION_ASK_QUESTION      => [$event->deny(), $event->grant()],
                default                                        => null,
            };
            if ($event->getQuestionId() !== null) {
                $seen[] = $event->getQuestionId();
            }
        });
        // A manager answers on someone's behalf; nobody may withdraw; the deny of asking sticks.
        $answer = $this->manager()->answer($this->owner, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
        self::assertSame('user:' . $this->owner->getId(), $answer->getAnsweredBySubject());
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->withdraw($this->owner, $question, 'No'));
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn (): TaskQuestion => $this->ask());
        self::assertContains($question->getId(), $seen);

        $access = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $access);
        $stranger = $this->user('stranger@example.com');
        self::assertFalse($access->canWithdrawQuestion($stranger, $question));
        self::assertFalse($access->canAnswerQuestion($stranger, $question, []));
        self::assertFalse($access->canAskQuestion($stranger, $this->task));
    }

    public function testAQuestionKeepsAtMostFiftyAnswers(): void
    {
        $question = $this->ask(mode: TaskQuestionChoiceMode::Single, other: true);
        for ($i = 1; $i <= TaskQuestionManager::MAX_ANSWERS; ++$i) {
            $this->manager()->answerAsSubject($question->getId(), $this->subject($this->bob), 'Bob', new TaskQuestionAnswerInput([], 'Answer ' . $i), $i);
        }
        $this->expectRefusal(TaskQuestionRefusedException::INVALID_ANSWER, fn (): TaskQuestionAnswer => $this->manager()->answerAsSubject($question->getId(), $this->subject($this->bob), 'Bob', new TaskQuestionAnswerInput([], 'One more'), 51), 'answers');
    }

    public function testAFreeTextQuestionAndTheReads(): void
    {
        $question = $this->ask(options: false);
        $this->login($this->bob);
        // Since 2.31.0 the answer form is the answer dialog's (the GET of task_question_answer).
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer', server: ['HTTP_X_NOWO_DIALOG' => '1']);
        $name    = 'qa_' . str_replace('-', '', $question->getId());
        self::assertCount(1, $crawler->filter('textarea[name="' . $name . '[otherText]"]'));
        self::assertCount(0, $crawler->filter('input[name^="' . $name . '[options]"]'));
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/answer', [$name => ['otherText' => 'Next week', 'version' => '1', '_token' => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $question->getId()))]]);
        self::assertResponseRedirects();

        self::assertSame([], $this->query()->openForSubjects([]));
        self::assertSame([], $this->query()->openAskedBy(new AppUser('unsaved@example.com')));
        $loaded = $this->em->find(TaskQuestion::class, $question->getId());
        self::assertInstanceOf(TaskQuestion::class, $loaded);
        $recipient = $loaded->getRecipients()[0];
        self::assertSame($loaded, $recipient->getQuestion());
        self::assertSame(0, $recipient->getPosition());
        self::assertNotSame('', $recipient->getId());
        $twig = self::getContainer()->get(TaskBoardTwigExtension::class);
        self::assertInstanceOf(TaskBoardTwigExtension::class, $twig);
        self::assertSame([], $twig->openQuestions([$this->task]));

        // The default resolver knows no task that is not stored.
        $resolver = self::getContainer()->get(DefaultTaskQuestionRecipientResolver::class);
        self::assertInstanceOf(DefaultTaskQuestionRecipientResolver::class, $resolver);
        $ghost = new Task(board: $this->roadmap, title: 'Ghost', creator: $this->owner);
        self::assertSame([], $resolver->candidates(Snapshots::task($ghost), Snapshots::board($this->roadmap), $this->owner));
    }

    public function testMyOpenQuestionsNeedASignedInUser(): void
    {
        $this->client->request('GET', '/tools/task-board/questions');
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function expectRefusal(string $reason, callable $call, string $field = ''): void
    {
        try {
            $call();
            self::fail('Not refused: ' . $reason);
        } catch (TaskQuestionRefusedException $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame($field, $refused->field);
        }
    }

    /** Audit TE-L3: a keyset cursor of another board (or tenant) is ignored, so its answer time never shows through. */
    public function testAStartIdOfAnotherBoardIsIgnored(): void
    {
        $question = $this->ask();
        $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
        $other     = $this->board('Secret', tenantKey: 'other-tenant');
        $otherTask = $this->task($other, 'Secret task');
        $otherTask->addMember(new TaskMember($otherTask, $this->bob, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->em->refresh($other);
        $foreign = $this->manager()->ask($this->owner, $otherTask, new TaskQuestionInput(text: 'Secret?', recipients: [$this->subject($this->bob)]));
        $this->manager()->answer($this->bob, $foreign, new TaskQuestionAnswerInput([], 'yes'));
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE task_board_task_questions SET answered_at = '2026-01-01 10:00:00' WHERE id = ?", [$question->getId()]);
        $connection->executeStatement("UPDATE task_board_task_questions SET answered_at = '2026-06-01 10:00:00' WHERE id = ?", [$foreign->getId()]);
        $this->em->clear();

        self::assertCount(1, $this->query()->answeredOnBoard($this->roadmap->getId()));
        self::assertCount(1, $this->query()->answeredOnBoard($this->roadmap->getId(), null, 100, $foreign->getId()), 'the foreign cursor changes nothing');
        self::assertCount(0, $this->query()->answeredOnBoard($this->roadmap->getId(), null, 100, $question->getId()), 'a cursor of the board still pages');
    }
}
