<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.24 (Beacon audit DATA-TASK-TOKEN-ERASURE): a disabled or erased user never acts, and a refused named user is
 * not replaced by the creator.
 */
final class ConnectionCreatorActorResolverTest extends TestCase
{
    /**
     * Since 1.24 (audit EX-L2): this used to fall back to the user who linked the board (1.23); a named actor whose
     * lookup fails, or that no longer exists, is now refused (fail closed).
     */
    public function testALookupErrorOrAVanishedNamedActorIsRefused(): void
    {
        $creator    = self::account(true);
        $board      = new TaskBoard('B', 'b', new stdClass());
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new TaskBoardConnection($board, 'clickup', 'clickup', 'ref', 'list-1', 'List', $creator));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willThrowException(new RuntimeException('bad id'));
        $entityManager->method('getRepository')->willReturn($repository);

        self::assertNull((new ConnectionCreatorActorResolver($entityManager, stdClass::class))->actorFor($board, 'x'));
        self::assertNull($this->resolver([], $creator)->actorFor($board, 'erased'), 'an erased named actor is not replaced by the linker');
        self::assertSame($creator, $this->resolver([], $creator)->actorFor($board, ''), 'no named actor: the linker');
    }

    public function testAnEnabledUserActsAndADisabledOneIsRefusedWithoutSubstitution(): void
    {
        $active   = $this->account(true);
        $disabled = $this->account(false);
        $board    = new TaskBoard('B', 'b', new stdClass());

        // The named user acts when enabled (and a user class without isEnabled() counts as enabled).
        self::assertSame($active, $this->resolver(['u1' => $active], $active)->actorFor($board, 'u1'));
        $plain = new stdClass();
        self::assertSame($plain, $this->resolver(['u3' => $plain], $active)->actorFor($board, 'u3'));
        // A disabled named user is refused, even though the creator is active: deny, do not substitute.
        self::assertNull($this->resolver(['u2' => $disabled], $active)->actorFor($board, 'u2'));
        // The creator acts for a run without a named user, unless disabled; no creator, no actor.
        self::assertSame($active, $this->resolver([], $active)->actorFor($board));
        self::assertNull($this->resolver([], $disabled)->actorFor($board));
        self::assertNull($this->resolver([], null)->actorFor($board));
    }

    public function testTheHostUserCheckerRefusesAccounts(): void
    {
        $board   = new TaskBoard('B', 'b', new stdClass());
        $erased  = new TestUser('9', 'erased@example.test');
        $kept    = new TestUser('1', 'kept@example.test');
        $checker = new class implements UserCheckerInterface {
            public function checkPreAuth(UserInterface $user): void
            {
                if ($user->getUserIdentifier() === 'erased@example.test') {
                    throw new DisabledException('Erased.');
                }
            }

            public function checkPostAuth(UserInterface $user, mixed ...$arguments): void
            {
            }
        };

        self::assertSame($kept, $this->resolver(['1' => $kept], null, $checker)->actorFor($board, '1'));
        self::assertNull($this->resolver(['9' => $erased], $kept, $checker)->actorFor($board, '9'));
        self::assertNull($this->resolver([], $erased, $checker)->actorFor($board));
    }

    /**
     * @param array<string, object> $users
     */
    private function resolver(array $users, ?object $creator, ?UserCheckerInterface $checker = null): ConnectionCreatorActorResolver
    {
        $board      = new TaskBoard('Linked', 'linked', new stdClass());
        $connection = $creator !== null ? new TaskBoardConnection($board, 'clickup', 'clickup', 'ref', 'list-1', 'List', $creator) : null;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($connection);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(static fn (string $class, mixed $id): ?object => $users[(string) $id] ?? null);
        $entityManager->method('getRepository')->willReturn($repository);

        return new ConnectionCreatorActorResolver($entityManager, stdClass::class, $checker);
    }

    private function account(bool $enabled): object
    {
        return new class($enabled) {
            public function __construct(private readonly bool $enabled)
            {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }
        };
    }
}
