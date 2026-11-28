<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\ClientApi;

use LogicException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientAuthenticator;
use Nowo\TaskBoardBundle\ClientApi\TaskClientCall;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 3.7.0: `POST logout` reads the principal the authenticator resolved; without one the request is not an
 * authenticated client API request (the listener answers `500 server_error`).
 */
final class TaskClientCallPrincipalTest extends TestCase
{
    public function testThePrincipalOfTheRequestOrAFailure(): void
    {
        $request   = new Request();
        $principal = new TaskClientPrincipal(new stdClass(), [], 'tok-1');
        $request->attributes->set(TaskClientAuthenticator::ATTRIBUTE, $principal);
        self::assertSame($principal, TaskClientCall::principalOf($request));

        $this->expectException(LogicException::class);
        TaskClientCall::principalOf(new Request());
    }
}
