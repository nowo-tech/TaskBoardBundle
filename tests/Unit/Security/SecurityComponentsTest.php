<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Security;

use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SecurityComponentsTest extends TestCase
{
    public function testConfigurableAccessChecker(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_USER');

        $checker = new ConfigurableTaskBoardAccessChecker($auth, ['ROLE_USER'], ['ROLE_ADMIN'], ['ROLE_USER']);
        $user    = new stdClass();

        self::assertTrue($checker->canAccess($user));
        self::assertFalse($checker->canCreateBoard($user));
        self::assertTrue($checker->canListBoards($user));
    }

    public function testNullTeamMembershipResolver(): void
    {
        self::assertSame([], (new NullTaskBoardTeamMembershipResolver())->resolveTeamIds(new stdClass()));
    }
}
