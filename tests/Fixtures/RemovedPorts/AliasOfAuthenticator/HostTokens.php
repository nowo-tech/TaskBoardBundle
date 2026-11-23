<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Fixtures\RemovedPorts\AliasOfAuthenticator;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 3.0 W1-1 fixture (autodiscovered, loadable): a host API authenticator aliased under the removed
 * TaskApiAuthenticatorInterface id instead of being named by `api.hooks.authenticator`.
 */
#[AsAlias('Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface')]
final class HostTokens implements TaskApiRequestAuthenticatorInterface
{
    public function authenticate(TaskApiRequestContext $request): TaskApiPrincipal|TaskApiAuthFailure
    {
        return TaskApiAuthFailure::Invalid;
    }
}
