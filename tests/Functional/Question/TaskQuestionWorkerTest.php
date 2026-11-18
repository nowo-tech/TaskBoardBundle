<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function gc_collect_cycles;
use function getenv;
use function memory_get_usage;
use function putenv;
use function str_replace;

/**
 * Beacon spec 242, REQ-RUNTIME-002: ONE kept-alive kernel built as production (`TASK_BOARD_TEST_DEBUG=0`), `http_kernel`
 * driven directly, the services resetter never called. The owner asks and Bob answers through the pages, 120 times;
 * once a forced failure breaks an answer's flush. Each write lands once, the manager is open after the failure, the
 * connection is back at level 0, and memory after a cycle collection grows less than 4 MiB after a 20-iteration warm-up.
 */
final class TaskQuestionWorkerTest extends QuestionTestCase
{
    private const ITERATIONS = 120;

    private const WARM_UP = 20;

    private const FAIL_AT = 40;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    public static bool $fail = false;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        parent::setUp();
        self::$fail = false;
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testAskAndAnswerAlternateWithoutTheResetter(): void
    {
        $owner = $this->sessionOf($this->owner);
        $bob   = $this->sessionOf($this->bob);
        $http  = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $this->em->getEventManager()->addEventListener(Events::postPersist, new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if (TaskQuestionWorkerTest::$fail && $args->getObject() instanceof TaskQuestionAnswer) {
                    TaskQuestionWorkerTest::$fail = false;

                    throw new RuntimeException('Forced failure');
                }
            }
        });
        $taskId   = $this->task->getId();
        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $request = Request::create('/tools/task-board/task/' . $taskId . '/question', 'POST', ['task_question' => [
                'text'       => 'Question ' . $i,
                'option1'    => 'Yes',
                'option2'    => 'No',
                'choiceMode' => 'single',
                'recipients' => [$this->subject($this->bob)],
                '_token'     => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('ask')),
            ]], $owner);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            self::assertSame(302, $response->getStatusCode(), 'ask ' . $i);
            $http->terminate($request, $response);

            $question = $this->em->getRepository(TaskQuestion::class)->findOneBy(['text' => 'Question ' . $i]);
            self::assertInstanceOf(TaskQuestion::class, $question, 'asked once ' . $i);
            $id         = $question->getId();
            $yes        = $question->getOptions()[0]->getId();
            $name       = 'qa_' . str_replace('-', '', $id);
            self::$fail = $i === self::FAIL_AT;
            $this->em->clear();

            $request = Request::create('/tools/task-board/task/' . $taskId . '/question/' . $id . '/answer', 'POST', [$name => [
                'options' => $yes,
                'version' => '1',
                '_token'  => PredictableCsrfTokenManager::value(TaskQuestionView::csrfId('answer', $id)),
            ]], $bob);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            $http->terminate($request, $response);
            $manager = $this->registry()->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            self::assertTrue($manager->isOpen(), 'the manager is open after request ' . $i);
            self::assertSame(0, $manager->getConnection()->getTransactionNestingLevel(), 'no transaction left open ' . $i);
            $this->em = $manager;
            $this->em->clear();
            $state = $this->em->find(TaskQuestion::class, $id)?->getState();
            if ($i === self::FAIL_AT) {
                self::assertSame(500, $response->getStatusCode(), 'the forced failure');
                self::assertSame(TaskQuestionState::Open, $state, 'nothing of the failed answer was saved');
                self::assertCount(0, $this->em->getRepository(TaskQuestionAnswer::class)->findBy(['question' => $id]));
            } else {
                self::assertSame(302, $response->getStatusCode(), 'answer ' . $i);
                self::assertSame(TaskQuestionState::Answered, $state, 'answered ' . $i);
            }
            $this->logger()->records = [];
            // Rule 7 of REQ-RUNTIME-002: a host without the resetter clears its own entity manager at every boundary.
            $this->em->clear();
            if ($i === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $baseline, 'memory stays flat');
        self::assertCount(self::ITERATIONS - 1, $this->em->getRepository(TaskQuestionAnswer::class)->findAll(), 'each answer once');
    }

    private function registry(): ManagerRegistry
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    /**
     * @return array<string, string>
     */
    private function sessionOf(AppUser $user): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }
}
