<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Controller\TaskBoardConnectionController;
use ReflectionMethod;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * Since 1.25 (audit EX-D1): a web request whose logged-in user has no readable id is refused (and logged) instead of
 * running the sync as the board's linker.
 */
final class ConnectionActorIdTest extends SyncFunctionalTestCase
{
    public function testAUserWithoutAnIdIsRefused(): void
    {
        $controller = self::getContainer()->get(TaskBoardConnectionController::class);
        self::assertInstanceOf(TaskBoardConnectionController::class, $controller);
        $actorId = new ReflectionMethod($controller, 'actorId');

        self::assertSame((string) $this->owner->getId(), $actorId->invoke($controller, $this->owner));

        $anonymous = new class implements UserInterface {
            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'no-id';
            }
        };
        try {
            $actorId->invoke($controller, $anonymous);
            self::fail('A user without an id must be refused.');
        } catch (AccessDeniedException) {
        }
        $reasons = array_map(static fn (array $record): mixed => $record['context']['reason'] ?? null, $this->logger()->records);
        self::assertContains('actor_id_missing', $reasons);
    }
}
