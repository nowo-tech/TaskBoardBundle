<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Stub;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 2.22.0 (audit SF4-I2): a host authenticator for the routing kernel with every feature on; it never authenticates.
 */
final class DenyingTaskApiAuthenticator implements TaskApiAuthenticatorInterface
{
    public function authenticate(Request $request): TaskApiPrincipal|TaskApiAuthFailure
    {
        return TaskApiAuthFailure::Invalid;
    }
}
