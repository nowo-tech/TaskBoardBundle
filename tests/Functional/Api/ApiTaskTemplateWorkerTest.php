<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Template\TaskTemplateData;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function getenv;
use function json_encode;
use function putenv;

/**
 * Beacon spec 234, FR-061 (REQ-RUNTIME-002): ONE kept-alive kernel built as production, `http_kernel` driven directly and
 * the services resetter never called. Two tenants' boards alternate over 100 iterations of the template list and a task
 * created from a template; each answer holds only its own tenant's templates.
 */
final class ApiTaskTemplateWorkerTest extends ApiTestCase
{
    private const ITERATIONS = 100;

    private string|false $debug = false;

    protected function setUp(): void
    {
        $this->debug        = getenv('TASK_BOARD_TEST_DEBUG');
        static::$hostConfig = ['task_templates' => ['enabled' => true]];
        putenv('TASK_BOARD_TEST_DEBUG=0');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv($this->debug === false ? 'TASK_BOARD_TEST_DEBUG' : 'TASK_BOARD_TEST_DEBUG=' . $this->debug);
        parent::tearDown();
    }

    public function testTwoTenantsAlternateWithoutTheResetter(): void
    {
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->issueToken();
        $manager = self::getContainer()->get(TaskTemplateManager::class);
        self::assertInstanceOf(TaskTemplateManager::class, $manager);
        $boards    = [];
        $templates = [];
        foreach (['a', 'b'] as $name) {
            $board            = $this->board('Board ' . $name, tenantKey: 'tenant-' . $name);
            $templates[$name] = $manager->createForBoard($this->owner, $board, new TaskTemplateData('own-' . $name, 'body-' . $name))->getId();
            $manager->createForTenant($this->owner, 'tenant-' . $name, new TaskTemplateData('shared-' . $name));
            $boards[$name] = $board->getId();
        }
        $this->em->clear();
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($boards as $name => $boardId) {
                $other   = $name === 'a' ? 'b' : 'a';
                $content = $this->request($http, 'GET', '/api/tasks/v2/list/' . $boardId . '/taskTemplate');
                self::assertStringContainsString('own-' . $name, $content, 'iteration ' . $i);
                self::assertStringContainsString('shared-' . $name, $content, 'iteration ' . $i);
                self::assertStringNotContainsString('-' . $other . '"', $content, 'iteration ' . $i);
                $content = $this->request($http, 'POST', '/api/tasks/v2/list/' . $boardId . '/task', (string) json_encode(['name' => 'T' . $i, 'template_id' => $templates[$name]]));
                self::assertStringContainsString('body-' . $name, $content, 'iteration ' . $i);
            }
            // Rule 7 of REQ-RUNTIME-002: a host without the resetter clears its own entity manager at every boundary.
            $this->em->clear();
        }
    }

    private function request(HttpKernel $http, string $method, string $uri, ?string $body = null): string
    {
        $request  = Request::create($uri, $method, [], [], [], ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'], $body);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame(200, $response->getStatusCode(), $uri . ' ' . $response->getContent());
        $content = (string) $response->getContent();
        $http->terminate($request, $response);

        return $content;
    }
}
