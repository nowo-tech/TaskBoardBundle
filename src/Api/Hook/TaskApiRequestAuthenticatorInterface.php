<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 3): the framework-free twin of {@see TaskApiAuthenticatorInterface}: who a request is,
 * from a {@see TaskApiRequestContext} (method, path, route, client IP, the `Authorization` credential and the headers)
 * instead of the HTTP request. Same contract otherwise: called once per request, the bundle stores no tokens.
 *
 * Set `api.hooks.authenticator` to a service implementing it. A service that also implements the deprecated
 * {@see TaskApiAuthenticatorInterface} is asked through that one until 3.0.
 */
interface TaskApiRequestAuthenticatorInterface
{
    public function authenticate(TaskApiRequestContext $request): TaskApiPrincipal|TaskApiAuthFailure;
}
