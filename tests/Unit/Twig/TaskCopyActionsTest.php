<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Spec 217, FR-005: without `move.enabled` or a user, the panel offers no Move or Duplicate (and asks no access check).
 */
final class TaskCopyActionsTest extends TestCase
{
    public function testNothingIsOfferedWithoutTheOptionAUserOrTheAccessChecker(): void
    {
        $user  = new TestUser('u1', 'u1');
        $task  = new Task(new TaskBoard('Board', 'board', $user), 'Task', $user);
        $token = new TokenStorage();

        self::assertFalse((new TaskBoardTwigExtension([], tokenStorage: $token, moveEnabled: false))->canCopy($task));
        self::assertFalse((new TaskBoardTwigExtension([], tokenStorage: $token, moveEnabled: true))->canCopy($task), 'no user');
        $token->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        self::assertFalse((new TaskBoardTwigExtension([], tokenStorage: $token, moveEnabled: true))->canCopy($task), 'no access checker');
    }
}
