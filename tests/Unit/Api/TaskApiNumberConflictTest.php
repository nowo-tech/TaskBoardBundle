<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Since 2.15.1 (audit OM TB-L2): a task that cannot be numbered in a look-alike scope answers 409 `TASK_003` on the API.
 */
final class TaskApiNumberConflictTest extends TestCase
{
    public function testTheNumberConflictIsA409(): void
    {
        $listener = new TaskApiRequestListener(
            $this->createStub(TaskApiAuthenticatorInterface::class),
            $this->createStub(TaskApiRateLimiterInterface::class),
            $this->createStub(TaskBoardAccessCheckerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            '/api',
        );
        $refusal = (new ReflectionMethod($listener, 'refusalFor'))->invoke($listener, TaskNumberScopeConflictException::forScope('acme'));

        self::assertInstanceOf(TaskApiRefusal::class, $refusal);
        self::assertSame(409, $refusal->status);
        self::assertSame('TASK_003', $refusal->ecode);
        self::assertSame(409, TaskNumberScopeConflictException::forScope('acme')->getStatusCode());
    }
}
