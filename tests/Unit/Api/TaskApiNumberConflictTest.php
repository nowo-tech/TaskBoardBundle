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
 * Since 2.16.0 (Beacon spec 234): the refusal names the task and the scope its own board asks for.
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
        $refusalFor = (new ReflectionMethod($listener, 'refusalFor'));
        $refusal    = $refusalFor->invoke($listener, TaskNumberScopeConflictException::forScope('acme'));

        self::assertInstanceOf(TaskApiRefusal::class, $refusal);
        self::assertSame(409, $refusal->status);
        self::assertSame('TASK_003', $refusal->ecode);
        self::assertSame(409, TaskNumberScopeConflictException::forScope('acme')->getStatusCode());
        // Since 2.16.0 (spec 234): the scope always, the task when the conflict named one; no other field of the task.
        self::assertSame(['err' => 'The task cannot be numbered in this scope', 'ECODE' => 'TASK_003', 'scope' => 'acme'], $refusal->body());

        $named = $refusalFor->invoke($listener, TaskNumberScopeConflictException::forScope('acme', 'task-7'));
        self::assertInstanceOf(TaskApiRefusal::class, $named);
        self::assertSame(['err' => 'The task cannot be numbered in this scope', 'ECODE' => 'TASK_003', 'task_id' => 'task-7', 'scope' => 'acme'], $named->body());
        self::assertSame('task-7', TaskNumberScopeConflictException::forScope('acme')->forTask('task-7')->taskId);
        self::assertSame('acme', TaskNumberScopeConflictException::forScope('acme')->forTask('task-7')->scopeKey);
    }
}
