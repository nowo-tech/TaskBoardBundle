<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Security;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientContextPolicyInterface;
use Nowo\TaskBoardBundle\ClientApi\Exception\TaskBoardModuleForbiddenException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\TaskClientApiException;
use Nowo\TaskBoardBundle\ClientApi\TaskClientCall;
use Nowo\TaskBoardBundle\Doctrine\LockConflictChain;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskClientApiDeniedEvent;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function in_array;
use function is_string;
use function max;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;

/**
 * Since 3.4.0 (client API v1): what every `/task-board/v1` request goes through before its controller, after the
 * firewall ran {@see TaskClientAuthenticator}, and the way every answer leaves. In this order, the first failure answers:
 *
 * 1. the principal the authenticator resolved, behind the same security user (else the host forgot the firewall:
 *    `500 server_error`, logged; the API fails closed);
 * 2. the person may use the task board now (`TaskBoardAccessCheckerInterface::canAccess()`) — `401 invalid_token` (audit reason
 *    `account_inactive`: a disabled or anonymised account); 3.5: when the account is active and the host's resolver
 *    signalled a lost Tasks permission with {@see TaskBoardModuleForbiddenException}, `403 module_forbidden` (`module: tasks`,
 *    no `WWW-Authenticate`, audit reason `module_forbidden`: the client hides the module and keeps its session);
 * 3. the route's scope (`task-board:read` for every GET, `task-board:write` for every write) — `403 insufficient_scope`;
 * 4. the context: the token's bound context, else the context header (`invalid_context` / `context_required`), checked
 *    by the host's `TaskClientContextPolicyInterface` on every request — `403 context_forbidden` (one answer); a
 *    `whereFor()` of `everything()` is a host error — `500 server_error`, logged (audit ZCS-L1);
 * 5. (3.5, audit ZDF-L1) the person's budget (`api.hooks.rate_limiter`, shared with their personal tokens) is consumed
 *    right after step 1, before steps 2 to 4 — `429 rate_limited`.
 *
 * `POST logout` (3.7.0, {@see self::NO_SCOPE}) runs step 1 only.
 *
 * Then the call holds a `TaskApiPrincipal` (level `Read` for `task-board:read`, `Tasks` with `task-board:write`, never
 * `Full` or `My tasks`; `where` from the context; channel `app`), so the existing guard and operations decide everything
 * else. Every refusal from step 2 on dispatches `TaskClientApiDeniedEvent` (ids and codes). Answers are
 * `Cache-Control: no-store, private` and never set a cookie. Stateless: nothing of a request is kept on the service.
 */
final readonly class TaskClientApiListener implements EventSubscriberInterface
{
    /** Route default marking the client API's routes, with the scope the route needs. */
    public const ROUTE_SCOPE = '_nowo_task_board_client_scope';

    /**
     * Since 3.7.0: the "scope" of `POST logout` — no scope, account, context or budget check (a token may always be given
     * up, and its read traffic can never starve its sign-out); only step 1 runs.
     */
    public const NO_SCOPE = '';

    private const RATE_LIMIT = '_nowo_task_board_client_rate_limit';

    /** Request attribute: true when a lock conflict was answered after a partial commit. */
    public const PARTIAL_COMMIT = '_nowo_task_board_client_partial_commit';

    /** The 403 codes of the Task API that are not a column transition policy's. */
    private const NOT_TRANSITION = ['ACCESS_002', 'TOKEN_001', 'PLAN_001', 'PLAN_002', 'TIME_001', 'TIME_002', 'TIME_003', 'FILE_001'];

    public function __construct(
        private TaskClientContextPolicyInterface $contexts,
        private TaskBoardAccessCheckerInterface $accessChecker,
        private TokenStorageInterface $tokenStorage,
        private TaskApiRateLimiterInterface $rateLimiter,
        private string $v1Prefix,
        private string $contextHeader = 'X-Beacon-Context',
        private ?EventDispatcherInterface $events = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?ConflictResponsePolicy $conflictPolicy = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // The firewall is at 8.
            KernelEvents::REQUEST   => ['onRequest', 7],
            KernelEvents::RESPONSE  => 'onResponse',
            KernelEvents::EXCEPTION => ['onException', 16],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $scope   = $request->attributes->get(self::ROUTE_SCOPE);
        if (!$event->isMainRequest() || !is_string($scope)) {
            return;
        }
        try {
            if ($scope === self::NO_SCOPE) {
                $this->client($request);

                return;
            }
            $request->attributes->set(TaskClientCall::ATTRIBUTE, $this->enter($request, $scope));
        } catch (TaskClientApiException $refusal) {
            $event->setResponse($this->answer($refusal));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isClientRequest($request)) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, private');
        // Stateless: never a cookie (a session a listener opened, a remember-me).
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }
        $state = $request->attributes->get(self::RATE_LIMIT);
        if ($state instanceof TaskApiRateLimitState) {
            $response->headers->set('X-RateLimit-Limit', (string) $state->limit);
            $response->headers->set('X-RateLimit-Remaining', (string) $state->remaining);
            $response->headers->set('X-RateLimit-Reset', (string) $state->resetAt);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isClientRequest($request)) {
            return;
        }
        $event->setResponse($this->answer($this->refusalFor($event->getThrowable(), $request)));
    }

    /**
     * @throws TaskClientApiException
     */
    private function enter(Request $request, string $scope): TaskClientCall
    {
        $client   = $this->client($request);
        $ipPrefix = TaskClientAuthenticator::ipPrefix($request->getClientIp());
        // Audit ZDF-L1: the token's budget comes first, so a client looping on a refusal (401 or 403) is bounded per token.
        $this->spend($request, $client, $ipPrefix);
        $forbidden = $request->attributes->get(TaskClientAuthenticator::MODULE_FORBIDDEN) === true;
        // Audit ZDH-L1: the account check always runs, so an inactive account is a 401 even when the resolver signalled.
        try {
            $active = $this->accessChecker->canAccess($client->user);
        } catch (TaskBoardModuleForbiddenException) {
            // Safety net only: the resolver is the supported signal (audit ZDH-M1); a checker that throws it is read as one.
            $active    = true;
            $forbidden = true;
        }
        if (!$active) {
            // A disabled or anonymised account: 401 as before 3.5 (the client signs out).
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, $client, $ipPrefix, ['WWW-Authenticate' => 'Bearer error="invalid_token"'], 'account_inactive');
        }
        if ($forbidden) {
            // 3.5 (audit ZDF-M1): only the host's explicit signal means "permission lost": 403, the client keeps its session.
            $this->deny('module_forbidden', Response::HTTP_FORBIDDEN, $client, $ipPrefix, [], null, ['message' => 'Tasks is not available for this account.', 'module' => 'tasks']);
        }
        if (!$client->hasScope($scope)) {
            $this->deny('insufficient_scope', Response::HTTP_FORBIDDEN, $client, $ipPrefix, ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', $scope)]);
        }
        $context = $this->context($client, $request->headers->get($this->contextHeader), $ipPrefix);
        $where   = $this->contexts->whereFor($client, $context);
        // Audit ZCS-L1: a context is a list of tenants; `everything()` would open every tenant of the person to by-id
        // reads and writes. A host error: fail closed, as for a missing firewall.
        if ($where->tenantKeys() === null) {
            $this->logger->error('TaskBoard client API: TaskClientContextPolicyInterface::whereFor() must not return TaskApiWhere::everything().', ['bundle' => 'nowo_task_board']);
            $this->deny('server_error', Response::HTTP_INTERNAL_SERVER_ERROR, $client, $ipPrefix, [], 'context_everything');
        }
        $level = $client->hasScope(TaskClientPrincipal::SCOPE_WRITE) ? TaskApiLevel::Tasks : TaskApiLevel::Read;

        return new TaskClientCall($client, new TaskApiPrincipal($client->user, $level, $where, $client->tokenId, $client->tokenName !== '' ? $client->tokenName : 'app', ColumnTransitionVia::APP), $context);
    }

    /**
     * Step 1: the principal the authenticator resolved, behind the same security user.
     *
     * @throws TaskClientApiException 500 when the host forgot the firewall
     */
    private function client(Request $request): TaskClientPrincipal
    {
        $client = $request->attributes->get(TaskClientAuthenticator::ATTRIBUTE);
        $user   = $this->tokenStorage->getToken()?->getUser();
        if (!$client instanceof TaskClientPrincipal || !$user instanceof UserInterface || ($user !== $client->user && (SyncUser::id($user) === null || SyncUser::id($user) !== SyncUser::id($client->user)))) {
            $this->logger->error('TaskBoard client API: no principal behind the security user. The client API must sit behind a stateless firewall with nowo_task_board.client_api.authenticator.', ['bundle' => 'nowo_task_board']);

            throw new TaskClientApiException('server_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $client;
    }

    /**
     * Consumes one request of the token's budget (audit ZDF-L1: before every refusal that follows the token's validation);
     * the budget is the token's and the person's, so it does not depend on the context.
     *
     * @throws TaskClientApiException 429 when spent
     */
    private function spend(Request $request, TaskClientPrincipal $client, ?string $ipPrefix): void
    {
        $level = $client->hasScope(TaskClientPrincipal::SCOPE_WRITE) ? TaskApiLevel::Tasks : TaskApiLevel::Read;
        $state = $this->rateLimiter->consume(new TaskApiPrincipal($client->user, $level, TaskApiWhere::limitedTo([], []), $client->tokenId, $client->tokenName !== '' ? $client->tokenName : 'app', ColumnTransitionVia::APP));
        $request->attributes->set(self::RATE_LIMIT, $state);
        if (!$state->accepted) {
            $this->deny('rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $client, $ipPrefix, ['Retry-After' => (string) max(1, $state->resetAt - $this->clock->now()->getTimestamp())]);
        }
    }

    /**
     * The context of the request: the token's bound context (a header naming another one is refused), else the header.
     *
     * @throws TaskClientApiException
     */
    private function context(TaskClientPrincipal $client, ?string $header, ?string $ipPrefix): string
    {
        if ($header !== null && (strlen($header) > TaskClientAuthenticator::MAX_CONTEXT_LENGTH || preg_match(TaskClientPrincipal::KEY_PATTERN, $header) !== 1)) {
            $this->deny('invalid_context', Response::HTTP_BAD_REQUEST, $client, $ipPrefix);
        }
        $context = $client->contextKey ?? $header;
        if ($context === null) {
            $this->deny('context_required', Response::HTTP_BAD_REQUEST, $client, $ipPrefix);
        }
        // A token bound to a context never opens another one; the membership is re-checked on every request.
        if (($header !== null && $header !== $context) || !$this->contexts->allows($client, $context)) {
            $this->deny('context_forbidden', Response::HTTP_FORBIDDEN, $client, $ipPrefix);
        }

        return $context;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra Body fields (3.5: `message` and `module` of `module_forbidden`)
     *
     * @throws TaskClientApiException always
     */
    private function deny(string $error, int $status, ?TaskClientPrincipal $client, ?string $ipPrefix, array $headers = [], ?string $reason = null, array $extra = []): never
    {
        $this->events?->dispatch(new TaskClientApiDeniedEvent($reason ?? $error, $client?->tokenId, $client instanceof TaskClientPrincipal ? SyncUser::id($client->user) : null, $ipPrefix), TaskBoardEvents::CLIENT_API_DENIED);

        throw new TaskClientApiException($error, $status, $extra, $headers);
    }

    private function isClientRequest(Request $request): bool
    {
        if ($request->attributes->has(self::ROUTE_SCOPE)) {
            return true;
        }

        // No route matched: an unknown path under the prefix answers like the rest of the client API.
        return !$request->attributes->has('_route') && str_starts_with($request->getPathInfo(), $this->v1Prefix . '/');
    }

    /**
     * The client API's answer to anything thrown by a controller or an operation (the Task API's refusals mapped to the
     * client API's codes; the class only in the log for the rest).
     */
    private function refusalFor(Throwable $throwable, Request $request): TaskClientApiException
    {
        if ($throwable instanceof TaskClientApiException) {
            // Audit ZBW-I2 (ChatKit): probing leaves a trace.
            return $throwable->status === Response::HTTP_NOT_FOUND ? $this->notFoundEvent($request) : $throwable;
        }
        if ($throwable instanceof TaskApiRefusal) {
            return $this->fromTaskApi($throwable, $request);
        }
        if ($throwable instanceof TaskNumberScopeConflictException) {
            return new TaskClientApiException('conflict', Response::HTTP_CONFLICT);
        }
        if ($throwable instanceof HttpExceptionInterface && in_array($throwable->getStatusCode(), [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED], true)) {
            return TaskClientApiException::notFound();
        }
        if ($throwable instanceof AccessDeniedException) {
            return new TaskClientApiException('forbidden', Response::HTTP_FORBIDDEN);
        }
        if (LockConflictChain::isConflict($throwable)) {
            $committed = LockConflictChain::committed($throwable);
            $request->attributes->set(self::PARTIAL_COMMIT, $committed);
            $this->logger->warning('TaskBoard client API: a write met a database lock conflict.', ['bundle' => 'nowo_task_board', 'exception_class' => $throwable::class]);

            return $this->lockConflict(TaskApiRefusal::lockConflict($this->conflictPolicy ?? new ConflictResponsePolicy(), $committed, LockConflictChain::retryAfterHint($throwable)));
        }
        $this->logger->error('TaskBoard client API: unexpected error.', ['bundle' => 'nowo_task_board', 'exception_class' => $throwable::class]);

        return new TaskClientApiException('server_error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * The Task API's refusal in the client API's words (§4.6 of the design).
     */
    private function fromTaskApi(TaskApiRefusal $refusal, Request $request): TaskClientApiException
    {
        return match (true) {
            $refusal->ecode === 'ACCESS_001', $refusal->ecode === 'API_001'                                         => $this->notFoundEvent($request),
            $refusal->ecode === 'ACCESS_002', $refusal->ecode === 'TOKEN_001'                                       => new TaskClientApiException('forbidden', Response::HTTP_FORBIDDEN),
            $refusal->ecode === 'PLAN_001'                                                                          => new TaskClientApiException('plan_limit', Response::HTTP_FORBIDDEN, ['reason' => 'tasks_module']),
            $refusal->ecode === 'PLAN_002'                                                                          => new TaskClientApiException('plan_limit', Response::HTTP_FORBIDDEN, ['reason' => 'limit']),
            $refusal->ecode === TaskApiRefusal::TASK_STALE                                                          => new TaskClientApiException('conflict', Response::HTTP_CONFLICT, ['currentRevision' => $refusal->extra['current_revision'] ?? null]),
            $refusal->ecode === TaskApiRefusal::CONFLICT, $refusal->ecode === TaskApiRefusal::CONFLICT_PARTIAL      => $this->lockConflict($refusal),
            str_starts_with($refusal->ecode, 'PARENT_')                                                             => new TaskClientApiException('parent_refused', $refusal->status === 400 ? Response::HTTP_BAD_REQUEST : Response::HTTP_CONFLICT, ['reason' => $this->parentReason($refusal->ecode)]),
            $refusal->ecode === 'IDEMP_001'                                                                         => new TaskClientApiException('idempotency_in_flight', Response::HTTP_CONFLICT),
            $refusal->ecode === 'IDEMP_002'                                                                         => new TaskClientApiException('idempotency_mismatch', Response::HTTP_CONFLICT),
            $refusal->ecode === 'IDEMP_003'                                                                         => TaskClientApiException::invalidField('Idempotency-Key'),
            $refusal->ecode === 'IDEMP_004'                                                                         => new TaskClientApiException('idempotency_unavailable', Response::HTTP_SERVICE_UNAVAILABLE),
            $refusal->ecode === 'AUDIT_001'                                                                         => new TaskClientApiException('audit_unavailable', Response::HTTP_SERVICE_UNAVAILABLE),
            $refusal->ecode === 'SEARCH_001'                                                                        => new TaskClientApiException('search_too_broad', Response::HTTP_BAD_REQUEST),
            $refusal->status === Response::HTTP_FORBIDDEN && !in_array($refusal->ecode, self::NOT_TRANSITION, true) => new TaskClientApiException('transition_refused', Response::HTTP_FORBIDDEN, ['policyCode' => $refusal->ecode, 'message' => $refusal->getMessage()]),
            $refusal->status === Response::HTTP_BAD_REQUEST                                                         => new TaskClientApiException('invalid_field', Response::HTTP_BAD_REQUEST, ['field' => null]),
            $refusal->status === Response::HTTP_CONFLICT                                                            => new TaskClientApiException('conflict', Response::HTTP_CONFLICT),
            $refusal->status === Response::HTTP_FORBIDDEN                                                           => new TaskClientApiException('forbidden', Response::HTTP_FORBIDDEN),
            default                                                                                                 => new TaskClientApiException('server_error', Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    /** `409 unavailable` (+ `Retry-After`) or, after a partial commit, `409 partial` (read again). */
    private function lockConflict(TaskApiRefusal $refusal): TaskClientApiException
    {
        return $refusal->ecode === TaskApiRefusal::CONFLICT_PARTIAL
            ? new TaskClientApiException('partial', $refusal->status, ['code' => TaskApiRefusal::CONFLICT_PARTIAL])
            : new TaskClientApiException('unavailable', $refusal->status, ['code' => TaskApiRefusal::CONFLICT], $refusal->headers);
    }

    private function parentReason(string $ecode): string
    {
        return match ($ecode) {
            TaskApiRefusal::PARENT_OTHER_BOARD => 'other_board',
            TaskApiRefusal::PARENT_SELF        => 'self',
            TaskApiRefusal::PARENT_DESCENDANT  => 'descendant',
            TaskApiRefusal::PARENT_TOO_DEEP    => 'too_deep',
            default                            => 'conflict',
        };
    }

    /** Dispatches the `not_found` refusal of an authenticated call and answers it. */
    private function notFoundEvent(Request $request): TaskClientApiException
    {
        $call = $request->attributes->get(TaskClientCall::ATTRIBUTE);
        if ($call instanceof TaskClientCall) {
            $this->events?->dispatch(new TaskClientApiDeniedEvent('not_found', $call->client->tokenId, SyncUser::id($call->client->user), TaskClientAuthenticator::ipPrefix($request->getClientIp())), TaskBoardEvents::CLIENT_API_DENIED);
        }

        return TaskClientApiException::notFound();
    }

    private function answer(TaskClientApiException $refusal): JsonResponse
    {
        return new JsonResponse($refusal->body(), $refusal->status, $refusal->headers);
    }
}
