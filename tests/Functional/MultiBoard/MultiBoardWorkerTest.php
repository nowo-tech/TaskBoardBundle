<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use Nowo\TaskBoardBundle\Controller\TaskBoardMultiBoardController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Form\MultiBoardTaskFilterType;
use Nowo\TaskBoardBundle\MultiBoard\DoctrineMultiBoardTaskQuery;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskList;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskPage;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkload;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardWorkloadRow;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function gc_collect_cycles;
use function getenv;
use function memory_get_usage;
use function ob_get_clean;
use function ob_start;
use function putenv;

/**
 * Since 2.13.0 (REQ-RUNTIME-002): ONE kept-alive kernel built as production (`TASK_BOARD_TEST_DEBUG=0`), `http_kernel`
 * driven directly and the services resetter never called. Alice and Bob, each with boards of their own in their scope,
 * alternate over 300 iterations of: the list, the workload and a CSV export of each (1,800 requests). Each response holds
 * only its own user's tasks and boards, and memory after a cycle collection grows less than 4 MiB after a 30-iteration
 * warm-up. The test host clears its entity manager at each boundary (rule 7).
 */
#[CoversClass(TaskBoardMultiBoardController::class)]
#[CoversClass(MultiBoardTaskList::class)]
#[CoversClass(DoctrineMultiBoardTaskQuery::class)]
#[CoversClass(MultiBoardTaskFilterType::class)]
#[CoversClass(MultiBoardBoard::class)]
#[CoversClass(MultiBoardPerson::class)]
#[CoversClass(MultiBoardTaskPage::class)]
#[CoversClass(MultiBoardWorkload::class)]
#[CoversClass(MultiBoardWorkloadRow::class)]
#[CoversClass(MultiBoardExportTable::class)]
#[CoversClass(XlsxStreamWriter::class)]
#[CoversClass(TaskBoardExtension::class)]
#[CoversClass(TaskBoardRouteLoader::class)]
final class MultiBoardWorkerTest extends MultiBoardTestCase
{
    private const ITERATIONS = 300;

    private const WARM_UP = 30;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug = getenv('TASK_BOARD_TEST_DEBUG');
        putenv('TASK_BOARD_TEST_DEBUG=0');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testTwoUsersAlternateWithoutTheResetter(): void
    {
        $users = ['alice' => $this->user('alice@example.com'), 'bob' => $this->user('bob@example.com')];
        foreach ($users as $name => $user) {
            $first  = $this->board(ucfirst($name) . ' one', creator: $user);
            $second = $this->board(ucfirst($name) . ' two', creator: $user);
            $this->make($first, $name . '-task-1', ['assignees' => [$user], 'tags' => [$name]]);
            $this->make($second, $name . '-task-2', ['assignees' => [$user], 'due' => '2020-01-01']);
            $this->allow($user, $first, $second);
        }
        $cookies = ['alice' => $this->sessionOf($users['alice']), 'bob' => $this->sessionOf($users['bob'])];
        $http    = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($cookies as $name => $session) {
                $other = $name === 'alice' ? 'bob' : 'alice';
                foreach ([self::URL, self::URL . '?view=people&label=' . $name, self::URL . '/export.csv?status=all'] as $uri) {
                    $content = $this->request($http, $uri, $session);
                    self::assertStringContainsString(str_contains($uri, 'people') ? $name . '@example.com' : $name . '-task-1', $content, 'iteration ' . $i . ': ' . $name . ' ' . $uri);
                    self::assertStringNotContainsString($other . '-task', $content, 'iteration ' . $i . ': ' . $name . ' ' . $uri);
                    self::assertStringNotContainsString(ucfirst($other) . ' one', $content, 'iteration ' . $i . ': ' . $name . ' ' . $uri);
                    self::assertStringNotContainsString($other . '@example.com', $content, 'iteration ' . $i . ': ' . $name . ' ' . $uri);
                }
            }
            $this->scope()->asked    = [];
            $this->logger()->records = [];
            // Rule 7 of REQ-RUNTIME-002: a host without the resetter clears its own entity manager at every boundary.
            $this->em->clear();
            if ($i === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
    }

    /**
     * @param array<string, string> $cookies
     */
    private function request(HttpKernel $http, string $uri, array $cookies): string
    {
        $request  = Request::create($uri, 'GET', [], $cookies);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode(), $uri);
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        } else {
            $content = (string) $response->getContent();
        }
        $http->terminate($request, $response);

        return $content;
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

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
