<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
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
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskApiRequestAuthenticatorInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskApiAuthenticatorInterface
{
    public function authenticate(Request $request): TaskApiPrincipal|TaskApiAuthFailure;
}
