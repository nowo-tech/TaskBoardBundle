<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\PrivateEntityManager;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function gc_collect_cycles;
use function getenv;
use function memory_get_usage;
use function putenv;

/**
 * Since 2.15.0 (REQ-RUNTIME-002 with `database.private_entity_manager: true`): ONE kept-alive kernel driven through
 * `http_kernel->handle()` / `terminate()` (never `Kernel::handle()`, which would run `services_resetter` from the second
 * request on). Two users alternate over 300 iterations: each moves their own task to the next or previous column (a
 * write through the private manager) and reads their board page, and is refused the other user's board. Every page shows
 * only that user's data and the task's current column; the private manager is dropped at every request boundary (no
 * TaskBoard row survives in it); a pending change of the application's manager is never flushed by the 1,200 TaskBoard
 * requests; memory stays within 4 MiB after a warm-up of 30 iterations. Built as production builds it
 * (`TASK_BOARD_TEST_DEBUG=0`).
 */
final class PrivateEntityManagerWorkerTest extends FunctionalTestCase
{
    private const ITERATIONS = 300;

    private const WARM_UP = 30;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        self::$taskBoardConfig = ['database' => ['private_entity_manager' => true]];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testTwoUsersWriteAndReadThroughThePrivateManagerWithoutTheResetter(): void
    {
        $alice    = $this->user('alice@example.com');
        $bob      = $this->user('bob@example.com');
        $boards   = ['alice@example.com' => $this->board('Alice board', creator: $alice), 'bob@example.com' => $this->board('Bob board', creator: $bob)];
        $tasks    = ['alice@example.com' => $this->task($boards['alice@example.com'], 'Alice secret task'), 'bob@example.com' => $this->task($boards['bob@example.com'], 'Bob secret task')];
        $titles   = ['alice@example.com' => 'Alice secret task', 'bob@example.com' => 'Bob secret task'];
        $cookies  = ['alice@example.com' => $this->sessionOf($alice), 'bob@example.com' => $this->sessionOf($bob)];
        $taskIds  = ['alice@example.com' => $tasks['alice@example.com']->getId(), 'bob@example.com' => $tasks['bob@example.com']->getId()];
        $boardIds = ['alice@example.com' => $boards['alice@example.com']->getId(), 'bob@example.com' => $boards['bob@example.com']->getId()];
        $this->em->clear();

        $container = self::getContainer();
        $http      = $container->get('http_kernel');
        $private   = $container->get('app.task_board_em');
        self::assertInstanceOf(HttpKernel::class, $http);
        self::assertInstanceOf(TaskBoardEntityManager::class, $private);
        // A change the application leaves pending in its own manager for the whole run: TaskBoard must never flush it.
        $host = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        $pending = new AppUser('pending@example.com');
        $host->persist($pending);

        $token    = PredictableCsrfTokenManager::value('task_board_task_advance');
        $logger   = $this->logger();
        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($taskIds as $email => $taskId) {
                $other  = $email === 'alice@example.com' ? 'bob@example.com' : 'alice@example.com';
                $action = $i % 2 === 0 ? 'next' : 'previous';
                $this->send($http, Request::create('/tools/task-board/task/' . $taskId . '/advance', 'POST', ['_token' => $token, 'action' => $action], $cookies[$email]), 302, $i);
                self::assertFalse($private->isCreated(), 'iteration ' . $i . ': the private manager is dropped at the end of the request');

                $content = $this->send($http, Request::create('/tools/task-board/board/' . $boardIds[$email], 'GET', [], $cookies[$email]), 200, $i);
                self::assertStringContainsString($titles[$email], $content, 'iteration ' . $i);
                self::assertStringNotContainsString($titles[$other], $content, 'iteration ' . $i . ': nothing of the other user');
                self::assertFalse($private->isCreated(), 'iteration ' . $i . ': no row survives the request');

                $this->send($http, Request::create('/tools/task-board/board/' . $boardIds[$other], 'GET', [], $cookies[$email]), 403, $i);
            }
            foreach ($logger->records as $record) {
                self::assertStringNotContainsString('pending change', $record['message'], 'iteration ' . $i . ': no TaskBoard change is left behind at a boundary');
            }
            $logger->records = [];
            if ($i === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending), 'the application change is still pending');
        $connection = $host->getConnection();
        $users      = $host->getClassMetadata(AppUser::class)->getTableName();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $users . ' WHERE email = ?', ['pending@example.com']));
        $tasksTable = $host->getClassMetadata(Task::class)->getTableName();
        $columns    = $connection->fetchFirstColumn('SELECT column_id FROM ' . $tasksTable . ' ORDER BY title');
        self::assertCount(2, $columns);
        self::assertSame($host, $private->source());
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
        self::assertNotSame([], $cookies);

        return $cookies;
    }

    private function send(HttpKernel $http, Request $request, int $status, int $iteration): string
    {
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame($status, $response->getStatusCode(), 'iteration ' . $iteration . ': ' . $request->getMethod() . ' ' . $request->getPathInfo());
        $http->terminate($request, $response);

        return (string) $response->getContent();
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
