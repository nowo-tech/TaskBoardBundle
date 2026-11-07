<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\Security\TaskApiIdempotencyListener;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyClaim;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Spec 213: when the store fails, a write with a key is refused before it runs, and a failure to keep an answer after the
 * write leaves the write done.
 */
final class TaskApiIdempotencyListenerTest extends TestCase
{
    public function testAStoreThatFailsToClaimRefusesTheWrite(): void
    {
        $listener = new TaskApiIdempotencyListener($this->failingStore(), (new ReflectionClass(TaskApiGuard::class))->newInstanceWithoutConstructor());

        try {
            $listener->onController($this->controllerEvent());
            self::fail('TaskApiRefusal expected');
        } catch (TaskApiRefusal $refusal) {
            self::assertSame([503, 'IDEMP_004'], [$refusal->status, $refusal->ecode]);
        }
    }

    public function testAStoreThatFailsToKeepTheAnswerLeavesTheWrite(): void
    {
        $listener = new TaskApiIdempotencyListener($this->failingStore(), (new ReflectionClass(TaskApiGuard::class))->newInstanceWithoutConstructor());
        $request  = $this->request();
        $request->attributes->set('_nowo_task_board_api_idempotency', ['scope', 'key']);
        $response = new Response('{}');

        $listener->onResponse(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('{}', $response->getContent());
    }

    public function testNothingHappensWithoutAKeyOrOnAReadOrASubRequest(): void
    {
        $store    = $this->failingStore();
        $listener = new TaskApiIdempotencyListener($store, (new ReflectionClass(TaskApiGuard::class))->newInstanceWithoutConstructor());
        $read     = $this->request('GET');
        $listener->onController(new ControllerEvent($this->kernel(), static fn (): Response => new Response(), $read, HttpKernelInterface::MAIN_REQUEST));
        $noKey = $this->request();
        $noKey->headers->remove(TaskApiIdempotencyListener::HEADER);
        $listener->onController(new ControllerEvent($this->kernel(), static fn (): Response => new Response(), $noKey, HttpKernelInterface::MAIN_REQUEST));
        $listener->onController(new ControllerEvent($this->kernel(), static fn (): Response => new Response(), $this->request(), HttpKernelInterface::SUB_REQUEST));
        $listener->onResponse(new ResponseEvent($this->kernel(), $this->request(), HttpKernelInterface::MAIN_REQUEST, new Response()));

        self::assertSame(0, $store->calls);
    }

    private function controllerEvent(): ControllerEvent
    {
        return new ControllerEvent($this->kernel(), static fn (): Response => new Response(), $this->request(), HttpKernelInterface::MAIN_REQUEST);
    }

    private function request(string $method = 'POST'): Request
    {
        $request = Request::create('/api/tasks/v2/task/x', $method, [], [], [], [], '{}');
        $request->attributes->set(TaskApiRequestListener::ROUTE_FLAG, true);
        $request->attributes->set('_nowo_task_board_api_principal', new TaskApiPrincipal(new stdClass(), TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name'));
        $request->headers->set(TaskApiIdempotencyListener::HEADER, 'key');

        return $request;
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }

    private function failingStore(): TaskApiIdempotencyStoreInterface
    {
        return new class implements TaskApiIdempotencyStoreInterface {
            public int $calls = 0;

            public function claim(string $scope, string $keyHash, string $fingerprint, DateTimeImmutable $expiresAt): TaskApiIdempotencyClaim
            {
                ++$this->calls;

                throw new RuntimeException('database down');
            }

            public function complete(string $scope, string $keyHash, int $status, string $body, ?string $tenantKey, ?string $boardId): void
            {
                ++$this->calls;

                throw new RuntimeException('database down');
            }

            public function release(string $scope, string $keyHash): void
            {
                ++$this->calls;
            }

            public function forget(string $scope): void
            {
            }

            public function purgeExpired(DateTimeImmutable $now): int
            {
                return 0;
            }
        };
    }
}
