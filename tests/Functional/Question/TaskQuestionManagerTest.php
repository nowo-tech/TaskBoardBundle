<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskQuestionAnsweredEvent;
use Nowo\TaskBoardBundle\Event\TaskQuestionAskedEvent;
use Nowo\TaskBoardBundle\Event\TaskQuestionWithdrawnEvent;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionConflictException;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function str_repeat;
use function strtoupper;
use function trim;

/**
 * Beacon spec 242: asking, answering (and superseding), withdrawing — the checks before any write, the version check of a
 * racing answer, the activity entries, the events after the commit, and the task's status that never moves.
 */
final class TaskQuestionManagerTest extends QuestionTestCase
{
    public function testAQuestionIsAskedWithItsOptionsRecipientsActivityAndEvent(): void
    {
        $revision = $this->task->getRevision();
        $column   = $this->task->getColumn()?->getId();
        $question = $this->ask();

        self::assertSame(TaskQuestionState::Open, $question->getState());
        self::assertSame(1, $question->getVersion());
        self::assertSame(['Hetzner', 'AWS', 'OVH'], array_map(static fn ($o): string => $o->getLabel(), $question->getOptions()));
        self::assertTrue($question->getOptions()[0]->isRecommended());
        self::assertSame($question, $question->getOptions()[0]->getQuestion());
        self::assertSame('EU, cheap', $question->getOptions()[0]->getDescription());
        self::assertSame([$this->subject($this->bob)], array_map(static fn ($r): string => $r->getSubjectId(), $question->getRecipients()));
        self::assertSame('bob@example.com', $question->getRecipients()[0]->getLabel());
        self::assertSame($this->owner, $question->getAskedBy());
        self::assertNull($question->getCurrentAnswer());

        $this->em->clear();
        $loaded = $this->query()->forTask($this->reloadTask());
        self::assertCount(1, $loaded);
        self::assertSame("## Why\n\nWe need it **today**.", $loaded[0]->getContext());
        $task = $this->reloadTask();
        self::assertSame($revision, $task->getRevision(), 'The task revision never moves');
        self::assertSame($column, $task->getColumn()?->getId(), 'The task status never moves');
        $history = $task->getChangeHistory()->filter(static fn ($e): bool => $e->getChangeType() === TaskChangeType::QuestionAsked);
        self::assertCount(1, $history);
        self::assertSame('Which hosting?', $history->first()->getNewValue());
        self::assertSame('question:' . $question->getId(), $history->first()->getContext());

        self::assertSame(['nowo_task_board.task_question_asked'], $this->eventNames());
        $event = $this->events[0][1];
        self::assertInstanceOf(TaskQuestionAskedEvent::class, $event);
        self::assertSame($question->getId(), $event->getQuestionId());
        self::assertSame($this->task->getId(), $event->getTaskId());
        self::assertSame($this->roadmap->getId(), $event->getBoardId());
        self::assertSame('acme', $event->getTenantKey());
        self::assertSame((string) $this->owner->getId(), $event->actorId);
        self::assertSame([$this->subject($this->bob)], $event->question->subjectIds());
    }

    public function testTheInputIsCheckedBeforeAnythingIsWritten(): void
    {
        $bob   = $this->subject($this->bob);
        $two   = [new TaskQuestionOptionInput('A'), new TaskQuestionOptionInput('B')];
        $cases = [
            'text'         => new TaskQuestionInput('   ', [$bob]),
            'text '        => new TaskQuestionInput(str_repeat('t', 501), [$bob]),
            'context'      => new TaskQuestionInput('Q', [$bob], context: str_repeat('c', 20001)),
            'options'      => new TaskQuestionInput('Q', [$bob], [new TaskQuestionOptionInput('Only one')]),
            'options '     => new TaskQuestionInput('Q', [$bob], [...$two, ...$two, new TaskQuestionOptionInput('E')]),
            'options  '    => new TaskQuestionInput('Q', [$bob], [new TaskQuestionOptionInput('Same'), new TaskQuestionOptionInput('same')]),
            'options   '   => new TaskQuestionInput('Q', [$bob], [new TaskQuestionOptionInput('A', null, true), new TaskQuestionOptionInput('B', null, true)]),
            'options    '  => new TaskQuestionInput('Q', [$bob], [new TaskQuestionOptionInput(' '), new TaskQuestionOptionInput('B')]),
            'options     ' => new TaskQuestionInput('Q', [$bob], [new TaskQuestionOptionInput('A', str_repeat('d', 501)), new TaskQuestionOptionInput('B')]),
            'choice'       => new TaskQuestionInput('Q', [$bob], [], TaskQuestionChoiceMode::Multiple),
            'recipients'   => new TaskQuestionInput('Q', []),
            'recipients '  => new TaskQuestionInput('Q', [$bob, $bob]),
            'recipients  ' => new TaskQuestionInput('Q', ['user:999']),
            // REQ-SEC-006: a look-alike of a candidate is not the candidate.
            'recipients   '  => new TaskQuestionInput('Q', ['USER:' . $this->bob->getId()]),
            'recipients    ' => new TaskQuestionInput('Q', [$this->subject($this->owner)]),
            'due_date'       => new TaskQuestionInput('Q', [$bob], dueOn: new DateTimeImmutable('-2 days')),
        ];
        foreach ($cases as $field => $input) {
            try {
                $this->manager()->ask($this->owner, $this->task, $input);
                self::fail('Accepted: ' . $field);
            } catch (TaskQuestionRefusedException $refused) {
                self::assertSame(TaskQuestionRefusedException::INVALID_QUESTION, $refused->reason, $field);
                self::assertSame(trim($field), $refused->field);
            }
        }
        self::assertSame([], $this->em->getRepository(TaskQuestion::class)->findAll());
        self::assertSame([], $this->eventNames());

        // No options: a free-text question, Other forced on; whitespace is folded.
        $free = $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput("  Why\nnot? ", [$bob], context: "  \n ", dueOn: new DateTimeImmutable('today')));
        self::assertSame('Why not?', $free->getText());
        self::assertTrue($free->allowsOther());
        self::assertNull($free->getContext());
        self::assertNotNull($free->getDueOn());
    }

    public function testAskingNeedsTheRightAnOpenTaskAndRoom(): void
    {
        $stranger = $this->user('stranger@example.com');
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->ask($stranger, $this->task, new TaskQuestionInput('Q', [$this->subject($this->bob)])));

        self::$taskBoardConfig = ['task_questions' => ['enabled' => true, 'max_open_per_task' => 1]];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->ask();
        $this->expectRefusal(TaskQuestionRefusedException::TASK_CLOSED, fn () => $this->ask(), 'open_questions');

        $this->task->archive();
        $this->em->flush();
        $this->expectRefusal(TaskQuestionRefusedException::TASK_CLOSED, fn () => $this->ask(), 'task');
    }

    public function testARecipientAnswersAndALaterAnswerSupersedesWithTheVersionRead(): void
    {
        $question        = $this->ask(other: true);
        $revision        = $this->reloadTask()->getRevision();
        [$hetzner, $aws] = $question->getOptions();
        $this->events    = [];

        $first = $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$hetzner->getId()]));
        self::assertSame(TaskQuestionState::Answered, $question->getState());
        self::assertSame(2, $question->getVersion());
        self::assertSame($first, $question->getCurrentAnswer());
        self::assertSame($this->subject($this->bob), $first->getAnsweredBySubject());
        self::assertSame('web', $first->getVia());
        self::assertSame('bob@example.com', $first->getAnsweredByName());
        self::assertSame([$hetzner], $first->getSelectedOptions());

        // A second answer without the version read is a conflict (the race of two first answers).
        try {
            $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$aws->getId()]));
            self::fail('A second first answer was accepted');
        } catch (TaskQuestionConflictException $conflict) {
            self::assertSame(2, $conflict->currentVersion);
        }
        // A stale version too.
        $this->expectConflict(fn () => $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$aws->getId()]), 1));

        $second = $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([], 'Neither: on premises'), 2);
        self::assertSame(3, $question->getVersion());
        self::assertSame($second, $question->getCurrentAnswer());
        self::assertTrue($first->isSuperseded());
        self::assertSame($second->getId(), $first->getSupersededById());
        self::assertSame([1, 2], array_map(static fn (TaskQuestionAnswer $a): int => $a->getSequence(), $question->getAnswers()));

        $this->em->clear();
        $task = $this->reloadTask();
        self::assertSame($revision, $task->getRevision(), 'Answering never moves the task revision');
        $rows = $task->getChangeHistory()->filter(static fn ($e): bool => $e->getChangeType() === TaskChangeType::QuestionAnswered)->getValues();
        self::assertCount(2, $rows);
        self::assertSame('Hetzner', $rows[1]->getOldValue());
        self::assertSame('Neither: on premises', $rows[1]->getNewValue());

        self::assertSame(['nowo_task_board.task_question_answered', 'nowo_task_board.task_question_answered'], $this->eventNames());
        $event = $this->events[1][1];
        self::assertInstanceOf(TaskQuestionAnsweredEvent::class, $event);
        self::assertSame('Neither: on premises', $event->answer->otherText);
        self::assertSame(['Hetzner'], $event->superseded?->optionLabels);
        self::assertSame((string) $this->bob->getId(), $event->actorId);
        self::assertSame('web', $event->getVia());
        self::assertSame($this->subject($this->bob), $event->getAnswererSubjectId());
    }

    public function testTheAnswerIsChecked(): void
    {
        $single    = $this->ask();
        $multi     = $this->ask(mode: TaskQuestionChoiceMode::Multiple, other: true);
        [$a, $b]   = $single->getOptions();
        [$ma, $mb] = $multi->getOptions();
        $cases     = [
            'options'    => [$single, new TaskQuestionAnswerInput([])],
            'options '   => [$single, new TaskQuestionAnswerInput([$a->getId(), $b->getId()])],
            'options  '  => [$single, new TaskQuestionAnswerInput([$ma->getId()])],
            'options   ' => [$multi, new TaskQuestionAnswerInput([$ma->getId(), $ma->getId()])],
            // @phpstan-ignore argument.type (the API passes what the client sent)
            'options    ' => [$multi, new TaskQuestionAnswerInput([7])],
            'other_text'  => [$single, new TaskQuestionAnswerInput([], 'free')],
            'other_text ' => [$multi, new TaskQuestionAnswerInput([], str_repeat('o', 5001))],
        ];
        foreach ($cases as $field => [$question, $input]) {
            try {
                $this->manager()->answer($this->bob, $question, $input);
                self::fail('Accepted: ' . $field);
            } catch (TaskQuestionRefusedException $refused) {
                self::assertSame(TaskQuestionRefusedException::INVALID_ANSWER, $refused->reason, $field);
                self::assertSame(trim($field), $refused->field);
            }
        }
        // Several options and Other together on a multiple-choice question, kept in the question's order.
        $answer = $this->manager()->answer($this->bob, $multi, new TaskQuestionAnswerInput([$mb->getId(), $ma->getId()], ' And more '));
        self::assertSame([$ma->getId(), $mb->getId()], $answer->getSelectedOptionIds());
        self::assertSame('And more', $answer->getOtherText());
        self::assertSame('Hetzner · AWS · And more', TaskQuestionManager::summary($answer));
    }

    public function testOnlyRecipientsAnswerUnlessTheHostGrantsIt(): void
    {
        $question = $this->ask();
        $option   = $question->getOptions()[0]->getId();
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->answer($this->owner, $question, new TaskQuestionAnswerInput([$option])));
        $stranger = $this->user('stranger@example.com');
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->answer($stranger, $question, new TaskQuestionAnswerInput([$option])));
        self::assertTrue($question->isOpen());
    }

    public function testTheAskerOrAManagerWithdrawsWithAReason(): void
    {
        $question     = $this->ask();
        $this->events = [];
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->withdraw($this->bob, $question, 'Not mine'));
        $this->expectRefusal(TaskQuestionRefusedException::INVALID_QUESTION, fn () => $this->manager()->withdraw($this->owner, $question, '  '), 'reason');
        $this->expectConflict(fn () => $this->manager()->withdraw($this->owner, $question, 'Old', 7));

        $this->manager()->withdraw($this->owner, $question, "Decided\nin the call", 1);
        self::assertTrue($question->isWithdrawn());
        self::assertSame('Decided in the call', $question->getWithdrawReason());
        self::assertSame($this->owner, $question->getWithdrawnBy());
        self::assertSame(2, $question->getVersion());
        self::assertSame(['nowo_task_board.task_question_withdrawn'], $this->eventNames());
        self::assertInstanceOf(TaskQuestionWithdrawnEvent::class, $this->events[0][1]);
        self::assertSame('Decided in the call', $this->events[0][1]->getReason());

        // Final: no answer, no second withdraw.
        $this->expectRefusal(TaskQuestionRefusedException::WITHDRAWN, fn () => $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()])));
        $this->expectRefusal(TaskQuestionRefusedException::WITHDRAWN, fn () => $this->manager()->withdraw($this->owner, $question, 'Again'));
        $this->expectRefusal(TaskQuestionRefusedException::WITHDRAWN, fn () => $this->manager()->answerAsSubject($question->getId(), $this->subject($this->bob), 'Bob', new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()])));
    }

    public function testAnExternalRecipientAnswersThroughTheHostsLink(): void
    {
        $question = $this->ask(other: true);
        $bob      = $this->subject($this->bob);
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->answerAsSubject($question->getId(), 'contact:9', 'Eve', new TaskQuestionAnswerInput([], 'x')));
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->answerAsSubject('missing', $bob, 'Eve', new TaskQuestionAnswerInput([], 'x')));
        $this->expectRefusal(TaskQuestionRefusedException::FORBIDDEN, fn () => $this->manager()->answerAsSubject('', $bob, 'Eve', new TaskQuestionAnswerInput([], 'x')));

        $answer = $this->manager()->answerAsSubject($question->getId(), $bob, '  ', new TaskQuestionAnswerInput([], 'From the client portal'), null, 'weird');
        self::assertNull($answer->getAnsweredBy());
        self::assertSame('bob@example.com', $answer->getAnsweredByName(), 'Without a label, the recipient\'s');
        self::assertTrue($answer->isExternal());
        $second = $this->manager()->answerAsSubject($question->getId(), $bob, 'Bob (client)', new TaskQuestionAnswerInput([], 'Changed my mind'), 2, 'api');
        self::assertSame('Bob (client)', $second->getAnsweredByName());
        self::assertSame('api', $second->getVia());

        $this->em->clear();
        // Two rows may share a second: find the one without a user (the external answer).
        $row = $this->reloadTask()->getChangeHistory()->filter(static fn ($e): bool => $e->getChangeType() === TaskChangeType::QuestionAnswered && $e->getUserName() === 'Bob (client)')->first();
        self::assertNotFalse($row);
        self::assertNull($row->getUser());
        self::assertSame('Bob (client)', $row->getUserName());
    }

    public function testAFailedWriteLeavesNothingBehind(): void
    {
        $question     = $this->ask();
        $this->events = [];
        $connection   = $this->em->getConnection();
        // The flush fails: the table of the row it writes is gone.
        $connection->executeStatement('DROP TABLE task_board_task_question_answers');
        try {
            $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
            self::fail('The answer was saved without its table');
        } catch (Throwable) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $this->eventNames(), 'No event for a rolled-back write');
        self::assertSame(0, $connection->getTransactionNestingLevel());
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->isOpen(), 'A closed manager is reset (worker mode)');
    }

    public function testErasureDetachesUsersAndForgetsSubjects(): void
    {
        $question = $this->ask(other: true);
        $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([], 'Yes'));
        $this->manager()->answerAsSubject($question->getId(), $this->subject($this->bob), 'Client Bob', new TaskQuestionAnswerInput([], 'Also yes'), 2);
        $id = $question->getId();

        self::assertSame(1, $this->manager()->detachUser($this->bob));
        self::assertSame(1, $this->manager()->detachUser($this->owner, true));
        self::assertSame(0, $this->manager()->detachUser(new AppUser('ghost@example.com')));
        $subject   = $this->subject($this->bob);
        $forgotten = TaskQuestionManager::forgottenSubject($subject);
        self::assertGreaterThan(0, $this->manager()->forgetSubject($subject));
        self::assertSame(0, $this->manager()->forgetSubject($subject), 'Idempotent');
        self::assertSame(0, $this->manager()->forgetSubject("bad\nid"));
        self::assertMatchesRegularExpression('/^forgotten:[0-9a-f]{16}$/', $forgotten);

        $this->em->clear();
        $loaded = $this->manager()->find($id);
        self::assertInstanceOf(TaskQuestion::class, $loaded);
        self::assertNull($loaded->getAskedBy());
        self::assertNull($loaded->getAskedByName());
        self::assertSame($forgotten, $loaded->getRecipients()[0]->getLabel());
        self::assertSame($forgotten, $loaded->getRecipients()[0]->getSubjectId());
        self::assertSame([$forgotten, $forgotten], array_map(static fn (TaskQuestionAnswer $a): string => $a->getAnsweredBySubject(), $loaded->getAnswers()));
        $names = array_map(static fn (TaskChangeHistory $h): ?string => $h->getUserName(), array_values(array_filter($loaded->getTask()->getChangeHistory()->toArray(), static fn (TaskChangeHistory $h): bool => $h->getUser() === null)));
        self::assertNotContains('Client Bob', $names);
        self::assertContains($forgotten, $names);
        self::assertSame([null, null], array_map(static fn (TaskQuestionAnswer $a): ?string => $a->getAnsweredByName(), $loaded->getAnswers()));
        self::assertSame(['Yes', 'Also yes'], array_map(static fn (TaskQuestionAnswer $a): ?string => $a->getOtherText(), $loaded->getAnswers()));
    }

    public function testTheQuestionIdIsMatchedExactlyAndOnItsTaskOnly(): void
    {
        $question = $this->ask();
        $other    = $this->task($this->roadmap, 'Other');
        self::assertSame($question, $this->manager()->find($question->getId()));
        self::assertNull($this->manager()->find(strtoupper($question->getId())));
        self::assertNull($this->manager()->findOnTask($other, $question->getId()));
        self::assertSame($question, $this->manager()->findOnTask($this->task, $question->getId()));
        self::assertSame(1, $this->manager()->openCount($this->task));
    }

    private function reloadTask(): Task
    {
        $task = $this->em->find(Task::class, $this->task->getId());
        self::assertInstanceOf(Task::class, $task);

        return $task;
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

    private function expectConflict(callable $call): void
    {
        try {
            $call();
            self::fail('No conflict');
        } catch (TaskQuestionConflictException) {
            self::addToAssertionCount(1);
        }
    }
}
