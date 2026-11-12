<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 2.15.0: asks a host {@see TaskApiRequestAuthenticatorInterface} where the bundle asks the deprecated {@see TaskApiAuthenticatorInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskApiRequestAuthenticatorGate implements TaskApiAuthenticatorInterface
{
    public function __construct(private TaskApiRequestAuthenticatorInterface $authenticator)
    {
    }

    public function authenticate(Request $request): TaskApiPrincipal|TaskApiAuthFailure
    {
        return $this->authenticator->authenticate(TaskApiRequestContexts::fromRequest($request));
    }
}
