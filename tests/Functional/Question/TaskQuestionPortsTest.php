<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionConflictException;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedQuestionPublisher;
use RuntimeException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

use function str_replace;

/**
 * Beacon spec 242: the host ports — a recipient resolver with external contacts, the signed answer links (shown to the
 * asker only, http / https only) and the decision publisher (outside the transaction, version-checked, http / https).
 */
final class TaskQuestionPortsTest extends QuestionTestCase
{
    protected function setUp(): void
    {
        ScriptedQuestionPublisher::reset();
        self::$taskBoardConfig = ['task_questions' => [
            'enabled'            => true,
            'recipient_resolver' => 'app.question_recipients',
            'external_answers'   => 'app.question_links',
            'decision_publisher' => 'app.question_publisher',
        ]];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        ScriptedQuestionPublisher::reset();
        parent::tearDown();
    }

    public function testExternalRecipientsGetSignedLinksOnTheAskersCardOnly(): void
    {
        $question = $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput('Budget?', [$this->subject($this->bob), 'contact:ok', 'contact:bad', 'contact:none'], [new TaskQuestionOptionInput('Yes'), new TaskQuestionOptionInput('No')]));
        self::assertTrue($question->recipient('contact:ok')?->isExternal());

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        $links   = $crawler->filter('[data-testid="nowo-task-question-copy-link"]');
        self::assertCount(1, $links);
        self::assertStringStartsWith('https://portal.example/answer/' . $question->getId(), (string) $links->attr('data-nowo-ui-clipboard-text'));

        $this->login($this->bob);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-question-copy-link"]'));
    }

    public function testTheDecisionIsPublishedThroughTheHost(): void
    {
        $question = $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput('Budget?', [$this->subject($this->bob)], [new TaskQuestionOptionInput('Yes'), new TaskQuestionOptionInput('No')]));
        self::assertNull($this->manager()->publishLabel($this->owner, $question), 'Not before an answer');
        $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[0]->getId()]));
        self::assertSame('Publish to wiki', $this->manager()->publishLabel($this->owner, $question));

        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertSame('Publish to wiki', trim($crawler->filter('[data-testid="nowo-task-question-publish"]')->text()));
        $name = 'qp_' . str_replace('-', '', $question->getId());
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/publish', [$name => ['_token' => PredictableCsrfTokenManager::value('task_board_question_publish_' . $question->getId())]]);
        self::assertResponseRedirects();
        self::assertCount(1, ScriptedQuestionPublisher::$published);
        self::assertSame(['Yes'], ScriptedQuestionPublisher::$published[0][1]->optionLabels);
        $crawler = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertSame('https://wiki.example/decisions/1', $crawler->filter('[data-testid="nowo-task-question-published"]')->attr('href'));
        self::assertSame('Wiki: decision 1', trim($crawler->filter('[data-testid="nowo-task-question-published"]')->text()));
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/publish', [$name => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);

        // The host hides the button: the route refuses.
        ScriptedQuestionPublisher::$label = null;
        $this->client->request('POST', '/tools/task-board/task/' . $this->task->getId() . '/question/' . $question->getId() . '/publish', [$name => ['_token' => PredictableCsrfTokenManager::value('task_board_question_publish_' . $question->getId())]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAPublicationIsCheckedAndAHostFailureSavesNothing(): void
    {
        $question = $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput('Budget?', [$this->subject($this->bob)], [new TaskQuestionOptionInput('Yes'), new TaskQuestionOptionInput('No')]));
        $this->manager()->answer($this->bob, $question, new TaskQuestionAnswerInput([$question->getOptions()[1]->getId()]));

        ScriptedQuestionPublisher::$url = 'javascript:alert(1)';
        try {
            $this->manager()->publish($this->owner, $question);
            self::fail('A javascript: URL was stored');
        } catch (TaskQuestionRefusedException $refused) {
            self::assertSame('published_url', $refused->field);
        }
        ScriptedQuestionPublisher::$url  = 'https://wiki.example/x';
        ScriptedQuestionPublisher::$fail = true;
        try {
            $this->manager()->publish($this->owner, $question);
            self::fail('The host failure was swallowed');
        } catch (RuntimeException $failure) {
            self::assertSame('The wiki is down', $failure->getMessage());
        }
        self::assertNull($question->getPublishedUrl());
        ScriptedQuestionPublisher::$fail = false;

        try {
            $this->manager()->publish($this->owner, $question, 1);
            self::fail('A stale version was published');
        } catch (TaskQuestionConflictException $conflict) {
            self::assertSame(2, $conflict->currentVersion);
        }
        $stranger = $this->user('stranger@example.com');
        $this->expectForbidden(fn () => $this->manager()->publish($stranger, $question));

        ScriptedQuestionPublisher::$publishedLabel = '  ';
        $publication                               = $this->manager()->publish($this->owner, $question, 2);
        self::assertSame('Publish to wiki', $publication->label, 'Without a label, the button\'s');
        self::assertSame(3, $question->getVersion());
        self::assertNotNull($question->getPublishedAt());

        $this->manager()->withdraw($this->owner, $question, 'Superseded by the call');
        $this->expectForbidden(fn () => $this->manager()->publish($this->owner, $question));
    }

    public function testTheViewWithoutASignedInUser(): void
    {
        $question = $this->manager()->ask($this->owner, $this->task, new TaskQuestionInput('Budget?', [$this->subject($this->bob)]));
        $view     = self::getContainer()->get(TaskQuestionView::class);
        self::assertInstanceOf(TaskQuestionView::class, $view);
        self::assertNull($view->section($this->task));
        self::assertSame([], $view->mine());
        self::assertSame([], $view->openCounts([]));
        self::assertSame([$this->task->getId() => 1], $view->openCounts([$this->task]));

        // The dashboard component, for the recipient.
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->bob, 'main', $this->bob->getRoles()));
        self::assertSame([$question], $view->mine(limit: 5));
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('@NowoTaskBoardBundle/manage/_my_questions.html.twig', ['limit' => 3]);
        self::assertStringContainsString('Budget?', $html);
        self::assertStringContainsString('nowo-task-question-' . $question->getId(), $html);
        $html = $twig->render('@NowoTaskBoardBundle/manage/_my_questions.html.twig', ['tab' => 'asked']);
        self::assertStringContainsString('None of your questions is waiting for an answer.', $html);
        self::assertInstanceOf(TaskQuestion::class, $this->manager()->find($question->getId()));
    }

    private function expectForbidden(callable $call): void
    {
        try {
            $call();
            self::fail('Not refused');
        } catch (TaskQuestionRefusedException $refused) {
            self::assertSame(TaskQuestionRefusedException::FORBIDDEN, $refused->reason);
        }
    }
}
