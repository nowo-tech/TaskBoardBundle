<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_column;
use function json_decode;
use function json_encode;

/**
 * Spec 234 (TaskBoard 2.16.0), REQ-RUNTIME-002: ONE kept-alive kernel, `http_kernel` driven directly (`handle()` and
 * `terminate()`), the services resetter never called. Two tokens of two tenants alternate a refused bulk update (a stale
 * revision), an accepted one, a text search and a member lookup over 100 iterations: every answer holds its own tenant's
 * data only, a refusal never leaves the entity manager closed or a value the rollback removed, and the revisions go up by
 * one per accepted write.
 */
final class ApiAiFlowWorkerTest extends ApiTestCase
{
    private const ITERATIONS = 100;

    public function testAlternatingTenantsInOneWorker(): void
    {
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $tasks                = [];
        foreach (['a', 'b'] as $name) {
            $email = $name . '@example.com';
            $this->user($email);
            $board        = $this->board('Board ' . $name, tenantKey: 'tenant-' . $name);
            $tasks[$name] = $this->task($board, 'Widget of ' . $name)->getId();
            $this->issueToken(TaskApiLevel::Full, TaskApiWhere::limitedTo(['tenant-' . $name], []), $email, 'bt_' . $name);
            ApiTestHost::$members['team-1'][] = new TaskApiMember('member-' . $name, 'Member ' . $name, $name === 'b');
        }
        // Each token reaches only the tenant of its own board.
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $manager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        $revisions = ['a' => 1, 'b' => 1];
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach (['a', 'b'] as $name) {
                $stale = $this->call($http, 'POST', '/api/tasks/v2/task/bulk', 'bt_' . $name, ['tasks' => [['id' => $tasks[$name], 'base_revision' => $revisions[$name] + 7, 'description' => 'lost ' . $i]]]);
                self::assertSame('BULK_001', $stale['ECODE'] ?? null, 'iteration ' . $i);
                self::assertTrue($manager->isOpen());

                $written = $this->call($http, 'POST', '/api/tasks/v2/task/bulk', 'bt_' . $name, ['tasks' => [['id' => $tasks[$name], 'base_revision' => $revisions[$name], 'description' => $name . ' ' . $i]]]);
                ++$revisions[$name];
                self::assertSame($revisions[$name], $written['tasks'][0]['revision'] ?? null, 'iteration ' . $i);
                self::assertSame($name . ' ' . $i, $written['tasks'][0]['description']);

                $found = $this->call($http, 'GET', '/api/tasks/v2/team/team-1/task?query=widget', 'bt_' . $name);
                self::assertSame(['Widget of ' . $name], array_column($found['tasks'], 'name'), 'iteration ' . $i);

                $members = $this->call($http, 'GET', '/api/tasks/v2/team/team-1/member?name=member', 'bt_' . $name);
                self::assertSame(['member-a', 'member-b'], array_column($members['members'], 'id'));
            }
            // Rule 7 of REQ-RUNTIME-002: a host without the resetter clears its own entity manager at every boundary.
            $manager->clear();
        }
        $manager->clear();
        self::assertSame('a 99', $manager->find(Task::class, $tasks['a'])?->getDescription());
        self::assertSame(self::ITERATIONS + 1, $manager->find(Task::class, $tasks['b'])?->getRevision());
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(HttpKernel $http, string $method, string $path, string $token, ?array $body = null): array
    {
        $request  = Request::create($path, $method, [], [], [], ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'application/json'], $body === null ? null : (string) json_encode($body));
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $decoded  = json_decode((string) $response->getContent(), true);
        $http->terminate($request, $response);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
