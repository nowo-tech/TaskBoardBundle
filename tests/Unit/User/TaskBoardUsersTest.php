<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\User;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Doctrine\Type\TaskBoardUserRefType;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\User\TaskBoardUserDirectoryInterface;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use PHPUnit\Framework\TestCase;
use stdClass;

use function in_array;
use function json_encode;
use function str_repeat;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2): user references by id — the value, the column type and the mode-aware helper.
 */
final class TaskBoardUsersTest extends TestCase
{
    public function testTheReferenceIsAnExactNonEmptyId(): void
    {
        $ref = new TaskBoardUserRef('Ana Domain');
        self::assertSame('Ana Domain', $ref->getId(), 'never trimmed or folded');
        self::assertSame('Ana Domain', (string) $ref);
        new TaskBoardUserRef(str_repeat('x', 128));
        self::assertTrue(TaskBoardUserRef::isValid('ünïcode-ü'));

        // Audit OC-M1 / OC-L2: what a PAD SPACE or folding collation, or an invisible character, would confuse.
        foreach (['', str_repeat('x', 129), 'u ', ' u', "u\t", "u\u{00A0}", "\u{3000}u", "u\u{200B}x", "u\u{FEFF}", "u\u{202E}x", "u\x00x", "u\xC3"] as $invalid) {
            self::assertFalse(TaskBoardUserRef::isValid($invalid), json_encode($invalid) ?: 'invalid UTF-8');
        }

        $this->expectException(InvalidArgumentException::class);
        new TaskBoardUserRef(' Ana ');
    }

    public function testAnEmptyReferenceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskBoardUserRef('');
    }

    public function testTheColumnTypeStoresIdsAndReadsReferences(): void
    {
        $type     = new TaskBoardUserRefType();
        $platform = new SQLitePlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertSame('u-1', $type->convertToDatabaseValue('u-1', $platform));
        self::assertSame('u-2', $type->convertToDatabaseValue(new TaskBoardUserRef('u-2'), $platform));
        self::assertSame('u-3', $type->convertToDatabaseValue(new TestUser('u-3', 'ana'), $platform), 'a host user by its getId()');
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertNull($type->convertToPHPValue('', $platform));
        self::assertEquals(new TaskBoardUserRef('U-1'), $type->convertToPHPValue('U-1', $platform));
        self::assertEquals(new TaskBoardUserRef('7'), $type->convertToPHPValue(7, $platform));
        foreach (['u-3 ', new TestUser("u\u{200B}3", 'zwsp'), str_repeat('x', 129)] as $lookAlike) {
            try {
                $type->convertToDatabaseValue($lookAlike, $platform);
                self::fail('refused before any write');
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidType::class);
        $type->convertToDatabaseValue(new stdClass(), $platform);
    }

    public function testTheColumnTypeRefusesAScalarThatIsNoId(): void
    {
        $this->expectException(InvalidType::class);
        (new TaskBoardUserRefType())->convertToDatabaseValue(1.5, new SQLitePlatform());
    }

    public function testTheEntityModeAnswersAs214(): void
    {
        $users = new TaskBoardUsers();
        $user  = new TestUser('u-1', 'ana');

        self::assertFalse($users->idMode());
        self::assertSame('IDENTITY(m.user)', $users->identity('m.user'));
        self::assertSame($user, $users->parameter($user));
        self::assertSame($user, $users->resolve($user));
        self::assertNull($users->resolve(null));
        self::assertNull($users->find('u-1'), 'no directory');
    }

    public function testTheIdModeFindsUsersExactlyAndForgetsThemAtTheBoundary(): void
    {
        $ana       = new TestUser('u-ana', 'ana');
        $directory = new class($ana) implements TaskBoardUserDirectoryInterface {
            public int $calls = 0;

            public function __construct(private readonly TestUser $ana)
            {
            }

            public function find(string $id): ?object
            {
                ++$this->calls;

                // A case-insensitive store: "U-ANA" answers with ana, whose id is "u-ana".
                return in_array($id, ['u-ana', 'U-ANA'], true) ? $this->ana : null;
            }
        };
        $users = new TaskBoardUsers(TaskBoardUsers::ID, $directory);

        self::assertTrue($users->idMode());
        self::assertSame('m.user', $users->identity('m.user'));
        self::assertSame('u-ana', $users->parameter($ana));
        self::assertSame($ana, $users->resolve(new TaskBoardUserRef('u-ana')));
        self::assertSame($ana, $users->find('u-ana'));
        self::assertSame(1, $directory->calls, 'kept for the unit of work');
        self::assertNull($users->find('U-ANA'), 'an answer whose id differs byte for byte is ignored');
        $ghost = new TaskBoardUserRef('u-ghost');
        self::assertSame($ghost, $users->resolve($ghost), 'an unknown id shows the reference');

        $users->reset();
        $users->find('u-ana');
        self::assertSame(4, $directory->calls, 'asked again after the boundary');
    }
}
