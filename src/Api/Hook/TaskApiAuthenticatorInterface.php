<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 2.2.0 (spec 213): who a request is. The bundle stores no tokens. Called once per request, so a revoked or expired
 * token, a disabled or erased account or a removed membership stops working at once. Required with `api.enabled`.
 *
 * {@see TaskApiCredential::fromRequest()} reads the credential. The API must sit behind a stateless
 * firewall whose security user is the principal's user, as access checkers read the security token: otherwise it answers 500.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see TaskApiRequestAuthenticatorInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface TaskApiAuthenticatorInterface
{
    public function authenticate(Request $request): TaskApiPrincipal|TaskApiAuthFailure;
}
