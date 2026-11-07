<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * A host's stateless firewall: it sets the security user for a known token and lets anything else through with no user,
 * so the bundle answers every refusal in one shape.
 */
final class ApiFirewallAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return isset(ApiTestHost::$tokens[(string) TaskApiCredential::fromRequest($request)]);
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge(ApiTestHost::$tokens[(string) TaskApiCredential::fromRequest($request)]['email']));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
