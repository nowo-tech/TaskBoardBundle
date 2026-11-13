<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRateLimitState;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
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
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;

use function in_array;
use function is_scalar;
use function method_exists;
use function str_starts_with;

/**
 * Since 2.2.0 (spec 213): what every API request goes through before its controller, on the API's routes only, and the way
 * every answer leaves. Below the firewall, so the security user is set. It fails closed: anything it cannot establish is a
 * refusal, and the credential is never logged or echoed.
 */
final readonly class TaskApiRequestListener implements EventSubscriberInterface
{
    /** Route default marking the API's routes. */
    public const ROUTE_FLAG = '_nowo_task_board_api';

    private const PRINCIPAL = '_nowo_task_board_api_principal';

    private const RATE_LIMIT = '_nowo_task_board_api_rate_limit';

    public function __construct(
        private TaskApiAuthenticatorInterface $authenticator,
        private TaskApiRateLimiterInterface $rateLimiter,
        private TaskBoardAccessCheckerInterface $accessChecker,
        private TokenStorageInterface $tokenStorage,
        private string $prefix,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // The firewall is at 8.
            KernelEvents::REQUEST  => ['onRequest', 7],
            KernelEvents::RESPONSE => 'onResponse',
            // Before the firewall's own (1) and the kernel's error page.
            KernelEvents::EXCEPTION => ['onException', 16],
        ];
    }

    public static function principalOf(Request $request): TaskApiPrincipal
    {
        $principal = $request->attributes->get(self::PRINCIPAL);

        return $principal instanceof TaskApiPrincipal ? $principal : throw new LogicException('Not an authenticated API request.');
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->getBoolean(self::ROUTE_FLAG)) {
            return;
        }

        try {
            $request->attributes->set(self::PRINCIPAL, $this->enter($request));
        } catch (TaskApiRefusal $refusal) {
            $event->setResponse($this->answer($refusal));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isApiRequest($request)) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, private');
        $state = $request->attributes->get(self::RATE_LIMIT);
        if ($state instanceof TaskApiRateLimitState) {
            $response->headers->set('X-RateLimit-Limit', (string) $state->limit);
            $response->headers->set('X-RateLimit-Remaining', (string) $state->remaining);
            $response->headers->set('X-RateLimit-Reset', (string) $state->resetAt);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApiRequest($event->getRequest())) {
            return;
        }
        $event->setResponse($this->answer($this->refusalFor($event->getThrowable())));
    }

    /**
     * @throws TaskApiRefusal
     */
    private function enter(Request $request): TaskApiPrincipal
    {
        $clientIp = (string) $request->getClientIp();
        $blocked  = $this->rateLimiter->failedTokenState($clientIp);
        if (!$blocked->accepted) {
            $request->attributes->set(self::RATE_LIMIT, $blocked);

            throw new TaskApiRefusal(Response::HTTP_TOO_MANY_REQUESTS, 'RATE_002', 'Too many failed tokens');
        }
        if (TaskApiCredential::fromRequest($request) === null) {
            throw new TaskApiRefusal(Response::HTTP_UNAUTHORIZED, 'OAUTH_017', 'Authorization header required');
        }

        $principal = $this->authenticator->authenticate($request);
        if ($principal === TaskApiAuthFailure::ApiDisabled) {
            throw new TaskApiRefusal(Response::HTTP_NOT_FOUND, 'API_001', 'Not found');
        }
        if ($principal === TaskApiAuthFailure::Invalid) {
            // @igor-ignore - Failed-token counter is meant to persist across requests (host rate limiter storage).
            $this->rateLimiter->registerFailedToken($clientIp);

            throw $this->invalidToken();
        }
        $this->requireSecurityUser($principal);
        if (!$this->accessChecker->canAccess($principal->user)) {
            // Audit IA-L1: the same answer as a bad token, and counted as one, so it cannot drive the host's
            // authenticator without limit.
            // @igor-ignore - Failed-token counter is meant to persist across requests (host rate limiter storage).
            $this->rateLimiter->registerFailedToken($clientIp);

            throw $this->invalidToken();
        }

        $state = $this->rateLimiter->consume($principal);
        $request->attributes->set(self::RATE_LIMIT, $state);
        if (!$state->accepted) {
            throw new TaskApiRefusal(Response::HTTP_TOO_MANY_REQUESTS, 'RATE_001', 'Rate limit reached');
        }

        return $principal;
    }

    /**
     * Access checkers read the security token, so a principal the firewall does not stand behind would be judged as someone
     * else, or as nobody.
     *
     * @throws TaskApiRefusal
     */
    private function requireSecurityUser(TaskApiPrincipal $principal): void
    {
        $securityUser = $this->tokenStorage->getToken()?->getUser();
        if ($securityUser === $principal->user || ($securityUser !== null && $this->idOf($securityUser) !== null && $this->idOf($securityUser) === $this->idOf($principal->user))) {
            return;
        }
        $this->logger->error('TaskBoard API: the security user is not the token\'s user. The API must sit behind a stateless firewall that authenticates the token.', ['bundle' => 'nowo_task_board']);

        throw $this->serverError();
    }

    private function idOf(object $user): ?string
    {
        $id = method_exists($user, 'getId') ? $user->getId() : null;

        return is_scalar($id) || $id instanceof Stringable ? (string) $id : null;
    }

    private function isApiRequest(Request $request): bool
    {
        if ($request->attributes->getBoolean(self::ROUTE_FLAG)) {
            return true;
        }

        // No route matched: an unknown path under the prefix answers like the rest of the API.
        return !$request->attributes->has('_route') && str_starts_with($request->getPathInfo() . '/', $this->prefix . '/');
    }

    private function refusalFor(Throwable $throwable): TaskApiRefusal
    {
        if ($throwable instanceof TaskApiRefusal) {
            return $throwable;
        }
        // Since 2.15.1 (REQ-SEC-006, audit OM TB-L2): the scope's number counter is a look-alike scope's.
        if ($throwable instanceof TaskNumberScopeConflictException) {
            return new TaskApiRefusal(Response::HTTP_CONFLICT, 'TASK_003', 'The task cannot be numbered in this scope');
        }
        if ($throwable instanceof HttpExceptionInterface && in_array($throwable->getStatusCode(), [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED], true)) {
            return TaskApiRefusal::notFound();
        }
        if ($throwable instanceof AccessDeniedException) {
            return TaskApiRefusal::permissionRefused();
        }
        if ($throwable instanceof AuthenticationException) {
            return $this->invalidToken();
        }
        // The class only: a message may hold user data.
        $this->logger->error('TaskBoard API: unexpected error.', ['bundle' => 'nowo_task_board', 'exception_class' => $throwable::class]);

        return $this->serverError();
    }

    private function invalidToken(): TaskApiRefusal
    {
        return new TaskApiRefusal(Response::HTTP_UNAUTHORIZED, 'OAUTH_019', 'Token not valid');
    }

    private function serverError(): TaskApiRefusal
    {
        return new TaskApiRefusal(Response::HTTP_INTERNAL_SERVER_ERROR, 'SERVER_001', 'Server error');
    }

    private function answer(TaskApiRefusal $refusal): JsonResponse
    {
        return new JsonResponse(['err' => $refusal->getMessage(), 'ECODE' => $refusal->ecode], $refusal->status);
    }
}
