<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

use function json_encode;

/**
 * Audit TE-L2: erasing an internal user through the account-erasure helper (`TaskSyncPeopleReassigner::detachUser()`,
 * anonymising) leaves no trace of them in the question rows — asker, answerer, the recipient row of their `user:<id>`
 * subject and the answers given for it — while the questions and the answers' content stay.
 */
final class TaskQuestionErasureTest extends QuestionTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['task_questions' => ['enabled' => true]] + SyncFunctionalTestCase::SYNC_CONFIG;
        parent::setUp();
    }

    public function testTheErasureHelperForgetsTheUserInTheQuestions(): void
    {
        $question = $this->ask();
        $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
        $id      = $question->getId();
        $subject = $this->subject($this->bob);

        $reassigner = self::getContainer()->get(TaskSyncPeopleReassigner::class);
        self::assertInstanceOf(TaskSyncPeopleReassigner::class, $reassigner);
        $reassigner->detachUser($this->bob, $this->owner, true);

        $connection = $this->em->getConnection();
        $rows       = [
            $connection->fetchAllAssociative('SELECT subject_id, label FROM task_board_task_question_recipients WHERE question_id = ?', [$id]),
            $connection->fetchAllAssociative('SELECT answered_by_id, answered_by_name, answered_by_subject, other_text FROM task_board_task_question_answers WHERE question_id = ?', [$id]),
        ];
        $dump = (string) json_encode($rows);
        self::assertStringNotContainsString('bob@example.com', $dump);
        self::assertStringNotContainsString($subject, $dump);
        self::assertStringContainsString(TaskQuestionManager::forgottenSubject($subject), $dump);
        $this->em->clear();
        $loaded = $this->em->find(TaskQuestion::class, $id);
        self::assertInstanceOf(TaskQuestion::class, $loaded);
        self::assertCount(1, $loaded->getAnswers(), 'the answer stays');
        self::assertNull($loaded->getAnswers()[0]->getAnsweredBy());
    }
}
