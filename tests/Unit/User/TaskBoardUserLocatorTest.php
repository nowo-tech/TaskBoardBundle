<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\User;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\User\TaskBoardUserDirectoryInterface;
use Nowo\TaskBoardBundle\User\TaskBoardUserLocator;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Since 2.29.0: the user behind a {@see UserRef} is found again, exactly, or is nobody.
 */
final class TaskBoardUserLocatorTest extends TestCase
{
    public function testIdModeAsksTheDirectoryAndKeepsTheExactAnswerOnly(): void
    {
        $directory = new class implements TaskBoardUserDirectoryInterface {
            public function find(string $id): ?object
            {
                if ($id === 'boom') {
                    throw new RuntimeException('directory down');
                }

                return new LocatedUser($id === 'look' ? 'LOOK' : $id);
            }
        };
        $locator = new TaskBoardUserLocator(stdClass::class, new TaskBoardUsers(TaskBoardUsers::ID, $directory));

        self::assertSame('u-1', $locator->find(UserRef::ofId('u-1'))?->getId());
        self::assertNull($locator->find(UserRef::ofId('look')), 'a look-alike answer is nobody');
        self::assertNull($locator->find(UserRef::ofId('boom')), 'a failing lookup is nobody');
    }

    public function testEntityModeFindsTheUserClassOnItsManager(): void
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getSingleIdentifierFieldName')->willReturn('id');
        $metadata->method('getTypeOfField')->willReturn('integer');
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('find')->willReturnCallback(static fn (string $class, mixed $id): LocatedUser => new LocatedUser((string) $id));
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $locator = new TaskBoardUserLocator(LocatedUser::class, new TaskBoardUsers(), $registry);

        self::assertSame('42', $locator->find(UserRef::ofId('42'))?->getId());
        self::assertNull($locator->find(UserRef::ofId('4x')), 'text for an integer id names nobody');
        self::assertNull((new TaskBoardUserLocator(LocatedUser::class, new TaskBoardUsers()))->find(UserRef::ofId('42')), 'no registry');
    }
}

final readonly class LocatedUser
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }
}
