<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Security;

use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientPrincipalResolverInterface;
use Nowo\TaskBoardBundle\ClientApi\Exception\TaskBoardModuleForbiddenException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientRequest;
use Nowo\TaskBoardBundle\ClientApi\TaskClientApiException;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskClientApiDeniedEvent;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function filter_var;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strncasecmp;
use function substr;
use function trim;

use const FILTER_VALIDATE_IP;

/**
 * Since 3.4.0 (client API v1, §3.3 of the Beacon Desktop tasks design): the stateless Symfony authenticator of the client
 * API (`nowo_task_board.client_api.authenticator`). TaskBoard's access checkers read the security token, so the host adds
 * this authenticator to a stateless firewall on `^%nowo_task_board.client_api.v1_path_prefix%/`:
 *
 *     task_board_client: { pattern: '^/api/task-board/v1/', stateless: true, provider: …,
 *                          custom_authenticators: [nowo_task_board.client_api.authenticator] }
 *
 * It asks the host's {@see TaskClientPrincipalResolverInterface} **once** for the Bearer token (at most
 * {@see self::MAX_TOKEN_LENGTH} bytes), sets the token's user as the security user and keeps the principal in the request
 * attributes ({@see self::ATTRIBUTE}). Anything else (no token, unknown, expired, revoked, another family, a user that is
 * not a `UserInterface`) answers the API's own `401 invalid_token` with `WWW-Authenticate: Bearer`, never a redirect,
 * and dispatches `TaskClientApiDeniedEvent`. Cookies and sessions are never read. Stateless: nothing of a request is
 * kept on the service.
 */
final class TaskClientAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /** Request attribute of the resolved principal. */
    public const ATTRIBUTE = '_nowo_task_board_client';

    /** Request attribute (3.5): the resolver signalled a lost Tasks permission with {@see TaskBoardModuleForbiddenException}. */
    public const MODULE_FORBIDDEN = '_nowo_task_board_client_module_forbidden';

    /** Longest Bearer token passed to the resolver (bytes). */
    public const MAX_TOKEN_LENGTH = 4096;

    /** Longest context header passed on (bytes); a longer one is `invalid_context` later. */
    public const MAX_CONTEXT_LENGTH = 128;

    public function __construct(
        private readonly TaskClientPrincipalResolverInterface $resolver,
        private readonly string $v1Prefix,
        private readonly string $contextHeader = 'X-Beacon-Context',
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), $this->v1Prefix . '/');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $bearer = self::bearer($request);
        if ($bearer === null) {
            throw new CustomUserMessageAuthenticationException('invalid_token');
        }
        $header = $request->headers->get($this->contextHeader);
        try {
            $principal = $this->resolver->resolve($bearer, new TaskClientRequest(
                $request->getClientIp(),
                $header === null ? null : substr($header, 0, self::MAX_CONTEXT_LENGTH),
                $request->getPathInfo(),
                $request->getMethod(),
            ));
        } catch (TaskBoardModuleForbiddenException $forbidden) {
            // 3.5: a valid token whose person lost Tasks: the listener answers 403 module_forbidden after the token budget.
            $principal = $forbidden->principal;
            $request->attributes->set(self::MODULE_FORBIDDEN, true);
        }
        if (!$principal instanceof TaskClientPrincipal || !$principal->user instanceof UserInterface) {
            throw new CustomUserMessageAuthenticationException('invalid_token');
        }
        $user = $principal->user;
        $request->attributes->set(self::ATTRIBUTE, $principal);

        // @igor-ignore - The loader runs once, now, for this request's passport; nothing keeps it.
        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        $request->attributes->remove(self::ATTRIBUTE);
        $request->attributes->remove(self::MODULE_FORBIDDEN);
        $this->events?->dispatch(new TaskClientApiDeniedEvent('invalid_token', null, null, self::ipPrefix($request->getClientIp())), TaskBoardEvents::CLIENT_API_DENIED);

        return self::invalidToken();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        return self::invalidToken();
    }

    /** `401 invalid_token` with `WWW-Authenticate: Bearer`. */
    public static function invalidToken(): JsonResponse
    {
        $refusal = new TaskClientApiException('invalid_token', Response::HTTP_UNAUTHORIZED, [], ['WWW-Authenticate' => 'Bearer error="invalid_token"']);

        return new JsonResponse($refusal->body(), $refusal->status, $refusal->headers + ['Cache-Control' => 'no-store, private']);
    }

    /** The plain Bearer token: null when absent, blank or longer than {@see self::MAX_TOKEN_LENGTH}. */
    public static function bearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH ? null : $token;
    }

    /** The network of an IP for the refusal events: IPv4 /24, IPv6 /64; null when unknown. */
    public static function ipPrefix(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return str_contains($ip, ':') ? IpUtils::anonymize($ip, 0, 8) . '/64' : IpUtils::anonymize($ip, 1) . '/24';
    }
}
