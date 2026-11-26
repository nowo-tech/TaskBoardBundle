<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\ClientApi;

use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientContextPolicyInterface;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientPrincipalResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientRequest;
use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientApiListener;
use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientAuthenticator;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

use function json_decode;

/**
 * Since 3.4.0 (client API v1): the listener's answers to what an operation throws (§4.6), and the requests it leaves alone.
 */
final class TaskClientApiListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Throwable, 1: int, 2: array<string, mixed>}>
     */
    public static function refusals(): iterable
    {
        yield 'plan module' => [new TaskApiRefusal(403, 'PLAN_001', 'x'), 403, ['error' => 'plan_limit', 'reason' => 'tasks_module']];
        yield 'plan limit' => [new TaskApiRefusal(403, 'PLAN_002', 'x'), 403, ['error' => 'plan_limit', 'reason' => 'limit']];
        yield 'level' => [TaskApiRefusal::levelRefused(), 403, ['error' => 'forbidden']];
        yield 'other 403' => [new TaskApiRefusal(403, 'TIME_001', 'x'), 403, ['error' => 'forbidden']];
        yield 'transition' => [new TaskApiRefusal(403, 'WIP_LIMIT', 'Full'), 403, ['error' => 'transition_refused', 'policyCode' => 'WIP_LIMIT', 'message' => 'Full']];
        yield 'stale' => [TaskApiRefusal::stale(4), 409, ['error' => 'conflict', 'currentRevision' => 4]];
        yield 'lock' => [new TaskApiRefusal(409, TaskApiRefusal::CONFLICT, 'x', [], ['Retry-After' => '1']), 409, ['error' => 'unavailable', 'code' => 'CONFLICT_001']];
        yield 'partial' => [new TaskApiRefusal(409, TaskApiRefusal::CONFLICT_PARTIAL, 'x'), 409, ['error' => 'partial', 'code' => 'CONFLICT_002']];
        yield 'parent other board' => [new TaskApiRefusal(400, TaskApiRefusal::PARENT_OTHER_BOARD, 'x'), 400, ['error' => 'parent_refused', 'reason' => 'other_board']];
        yield 'parent self' => [new TaskApiRefusal(400, TaskApiRefusal::PARENT_SELF, 'x'), 400, ['error' => 'parent_refused', 'reason' => 'self']];
        yield 'parent descendant' => [new TaskApiRefusal(409, TaskApiRefusal::PARENT_DESCENDANT, 'x'), 409, ['error' => 'parent_refused', 'reason' => 'descendant']];
        yield 'parent too deep' => [new TaskApiRefusal(409, TaskApiRefusal::PARENT_TOO_DEEP, 'x'), 409, ['error' => 'parent_refused', 'reason' => 'too_deep']];
        yield 'parent conflict' => [new TaskApiRefusal(409, TaskApiRefusal::PARENT_CONFLICT, 'x'), 409, ['error' => 'parent_refused', 'reason' => 'conflict']];
        yield 'idempotency in flight' => [new TaskApiRefusal(409, 'IDEMP_001', 'x'), 409, ['error' => 'idempotency_in_flight']];
        yield 'idempotency mismatch' => [new TaskApiRefusal(409, 'IDEMP_002', 'x'), 409, ['error' => 'idempotency_mismatch']];
        yield 'idempotency key' => [new TaskApiRefusal(400, 'IDEMP_003', 'x'), 400, ['error' => 'invalid_field', 'field' => 'Idempotency-Key']];
        yield 'idempotency store' => [new TaskApiRefusal(503, 'IDEMP_004', 'x'), 503, ['error' => 'idempotency_unavailable']];
        yield 'audit' => [new TaskApiRefusal(503, 'AUDIT_001', 'x'), 503, ['error' => 'audit_unavailable']];
        yield 'search' => [new TaskApiRefusal(400, 'SEARCH_001', 'x'), 400, ['error' => 'search_too_broad']];
        yield 'input' => [new TaskApiRefusal(400, 'INPUT_002', 'x'), 400, ['error' => 'invalid_field', 'field' => null]];
        yield 'other 409' => [new TaskApiRefusal(409, 'LIST_001', 'x'), 409, ['error' => 'conflict']];
        yield 'other status' => [new TaskApiRefusal(500, 'SERVER_001', 'x'), 500, ['error' => 'server_error']];
        yield 'not found' => [TaskApiRefusal::notFound(), 404, ['error' => 'not_found']];
        yield 'number scope' => [TaskNumberScopeConflictException::forScope('s', 't'), 409, ['error' => 'conflict']];
        yield 'access denied' => [new AccessDeniedException(), 403, ['error' => 'forbidden']];
        yield 'deadlock' => [new RuntimeException('x', 0, (new ReflectionClass(DeadlockException::class))->newInstanceWithoutConstructor()), 409, ['error' => 'unavailable', 'code' => 'CONFLICT_001']];
        yield 'anything else' => [new RuntimeException('secret detail'), 500, ['error' => 'server_error']];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @dataProvider refusals
     */
    #[DataProvider('refusals')]
    public function testWhatAnOperationThrowsIsAnsweredInTheClientApisWords(Throwable $thrown, int $status, array $body): void
    {
        $request = Request::create('/api/task-board/v1/me');
        $request->attributes->set(TaskClientApiListener::ROUTE_SCOPE, TaskClientPrincipal::SCOPE_READ);
        $event = new ExceptionEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $thrown);
        $this->listener()->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['ok' => false] + $body, json_decode((string) $response->getContent(), true));
    }

    public function testOtherRequestsAreLeftAlone(): void
    {
        $listener = $this->listener();
        $other    = Request::create('/elsewhere');
        $request  = new RequestEvent($this->kernel(), $other, HttpKernelInterface::MAIN_REQUEST);
        $listener->onRequest($request);
        self::assertNull($request->getResponse());

        $response = new Response('x');
        $listener->onResponse(new ResponseEvent($this->kernel(), $other, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertFalse($response->headers->has('X-RateLimit-Limit'));

        $exception = new ExceptionEvent($this->kernel(), $other, HttpKernelInterface::MAIN_REQUEST, new RuntimeException());
        $listener->onException($exception);
        self::assertNull($exception->getResponse());
        self::assertArrayHasKey('kernel.request', TaskClientApiListener::getSubscribedEvents());
    }

    public function testAnswersNeverSetACookie(): void
    {
        $request = Request::create('/api/task-board/v1/me');
        $request->attributes->set(TaskClientApiListener::ROUTE_SCOPE, TaskClientPrincipal::SCOPE_READ);
        $response = new Response('x');
        $response->headers->setCookie(Cookie::create('session', 'abc'));
        $this->listener()->onResponse(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testTheAuthenticatorsEntryPointAndNetworks(): void
    {
        self::assertSame(401, (new TaskClientAuthenticator($this->resolver(), '/v1'))->start(Request::create('/v1/me'))->getStatusCode());
        self::assertNull(TaskClientAuthenticator::ipPrefix(null));
        self::assertNull(TaskClientAuthenticator::ipPrefix('not an ip'));
        self::assertSame('203.0.113.0/24', TaskClientAuthenticator::ipPrefix('203.0.113.9'));
        self::assertSame('2001:db8:1:2::/64', TaskClientAuthenticator::ipPrefix('2001:db8:1:2:3:4:5:6'));
        self::assertNull((new TaskClientAuthenticator($this->resolver(), '/v1'))->onAuthenticationSuccess(Request::create('/v1/me'), new NullToken(), 'main'));
    }

    private function resolver(): TaskClientPrincipalResolverInterface
    {
        return new class implements TaskClientPrincipalResolverInterface {
            public function resolve(string $bearer, TaskClientRequest $request): ?TaskClientPrincipal
            {
                return null;
            }
        };
    }

    private function listener(): TaskClientApiListener
    {
        $contexts = new class implements TaskClientContextPolicyInterface {
            public function contextsFor(TaskClientPrincipal $principal): array
            {
                return [];
            }

            public function allows(TaskClientPrincipal $principal, string $contextKey): bool
            {
                return false;
            }

            public function whereFor(TaskClientPrincipal $principal, string $contextKey): TaskApiWhere
            {
                return TaskApiWhere::limitedTo([], []);
            }
        };
        $access = $this->createStub(TaskBoardAccessCheckerInterface::class);
        $limits = new class implements TaskApiRateLimiterInterface {
            public function consume(TaskApiPrincipal $principal): TaskApiRateLimitState
            {
                return new TaskApiRateLimitState(true, 1, 1, 1);
            }

            public function failedTokenState(string $clientIp): TaskApiRateLimitState
            {
                return new TaskApiRateLimitState(true, 1, 1, 1);
            }

            public function registerFailedToken(string $clientIp): void
            {
            }
        };

        return new TaskClientApiListener($contexts, $access, new TokenStorage(), $limits, '/api/task-board/v1');
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}
