<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_key_exists;
use function getenv;
use function json_decode;
use function json_encode;
use function putenv;

/**
 * Beacon spec 234, T004 (REQ-RUNTIME-002): ONE kept-alive kernel built as production, `http_kernel` driven directly and
 * the services resetter never called, the entity manager never cleared. Two tenants' trees change in turn over 60
 * iterations: an attach, a refused cycle, a detach, and every tenth iteration a change committed by another process
 * behind the identity map's back. Every answer is the committed tree; nothing a request held reaches the next one.
 */
final class ApiTaskReparentWorkerTest extends ApiTestCase
{
    private const ITERATIONS = 60;

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

    public function testTwoTenantsTreesChangeInTurnWithoutTheResetter(): void
    {
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->issueToken();
        $trees = [];
        foreach (['a', 'b'] as $name) {
            $board        = $this->board('Board ' . $name, tenantKey: 'tenant-' . $name);
            $parent       = $this->task($board, 'Epic ' . $name);
            $task         = $this->task($board, 'Story ' . $name);
            $child        = $this->task($board, 'Sub ' . $name, parent: $task);
            $trees[$name] = [$parent->getId(), $task->getId(), $child->getId()];
        }
        $updated    = 0;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$updated): void {
            $updated += $event->hasChanged('parent') ? 1 : 0;
        });
        $http = self::getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $connection = $this->em->getConnection();

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            foreach ($trees as $name => [$parentId, $taskId, $childId]) {
                if ($i % 10 === 9) {
                    // Another process put the task under the epic; this manager still holds it at the top level.
                    $connection->executeStatement('UPDATE task_board_tasks SET parent_id = ? WHERE id = ?', [$parentId, $taskId]);
                    [$status, $answer] = $this->request($http, $taskId, ['parent' => null]);
                    self::assertSame([200, null], [$status, array_key_exists('parent', $answer) ? $answer['parent'] : 'missing'], 'iteration ' . $i . ' ' . $name);
                }
                [$status, $answer] = $this->request($http, $taskId, ['parent' => $parentId]);
                self::assertSame([200, $parentId], [$status, $answer['parent'] ?? null], 'iteration ' . $i . ' ' . $name);
                [$status, $answer] = $this->request($http, $parentId, ['parent' => $childId]);
                self::assertSame([409, 'PARENT_003'], [$status, $answer['ECODE'] ?? null], 'iteration ' . $i . ' ' . $name);
                [$status, $answer] = $this->request($http, $taskId, ['parent' => null]);
                self::assertSame([200, null], [$status, array_key_exists('parent', $answer) ? $answer['parent'] : 'missing'], 'iteration ' . $i . ' ' . $name);
                self::assertNull($connection->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$taskId]));
                self::assertSame($taskId, $connection->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$childId]));
                self::assertSame(0, $connection->getTransactionNestingLevel());
            }
        }

        // Two writes per tenant and iteration, plus the detach after each out-of-band change: each one event, after its commit.
        self::assertSame(2 * (self::ITERATIONS * 2 + self::ITERATIONS / 10), $updated);
        // Nothing is held: an event now goes straight through.
        $deferral = self::getContainer()->get(TaskEventDeferral::class);
        self::assertInstanceOf(TaskEventDeferral::class, $deferral);
        $before = $updated;
        $deferral->dispatchSaved(new TaskUpdatedEvent($this->task($this->board('Probe', tenantKey: 'tenant-a'), 'Probe'), $this->owner, ['parent']), TaskBoardEvents::TASK_UPDATED);
        self::assertSame($before + 1, $updated);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(HttpKernel $http, string $taskId, array $body): array
    {
        $request  = Request::create('/api/tasks/v2/task/' . $taskId, 'PUT', [], [], [], ['HTTP_AUTHORIZATION' => self::TOKEN, 'CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $http->terminate($request, $response);
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        return [$response->getStatusCode(), $decoded];
    }
}
