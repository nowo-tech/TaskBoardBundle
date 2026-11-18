<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Nowo\TaskBoardBundle\Api\Security\TaskApiActors;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 2.22.0 (Beacon spec 241 T020): the principal's user as the entity manager manages it in this attempt of a write
 * unit; anything the manager does not map, or cannot find, is kept as it is.
 */
final class TaskApiActorsTest extends TestCase
{
    public function testAManagedUserIsKept(): void
    {
        $user = new TestUser('1', 'ana');
        $em   = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->expects(self::never())->method('find');

        self::assertSame($user, (new TaskApiActors($em))->managed($user));
    }

    public function testADetachedUserIsFoundAgainByItsIdentifier(): void
    {
        $detached = new TestUser('1', 'ana');
        $managed  = new TestUser('1', 'ana');
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => '1']);
        $metadata->method('getName')->willReturn(TestUser::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('find')->with(TestUser::class, ['id' => '1'])->willReturn($managed);

        $request   = new Request(attributes: ['_nowo_task_board_api_principal' => new TaskApiPrincipal($detached, TaskApiLevel::Full, TaskApiWhere::everything(), 't', 'n')]);
        $principal = (new TaskApiActors($em))->principal($request);
        self::assertSame($managed, $principal->user);
        self::assertSame(['t', 'n'], [$principal->tokenId, $principal->tokenName]);
        self::assertSame($principal, $principal->withUser($managed));
        self::assertSame($detached, TaskApiRequestListener::principalOf($request)->user, 'the request keeps its own principal');
    }

    public function testWhatTheManagerCannotFindIsKept(): void
    {
        $user = new stdClass();

        $unmapped = $this->createMock(EntityManagerInterface::class);
        $unmapped->method('getClassMetadata')->willThrowException(new MappingException('not mapped'));
        self::assertSame($user, (new TaskApiActors($unmapped))->managed($user));

        $noId = $this->createMock(ClassMetadata::class);
        $noId->method('getIdentifierValues')->willReturn([]);
        $withoutId = $this->createMock(EntityManagerInterface::class);
        $withoutId->method('getClassMetadata')->willReturn($noId);
        self::assertSame($user, (new TaskApiActors($withoutId))->managed($user));

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 9]);
        $metadata->method('getName')->willReturn(stdClass::class);
        $gone = $this->createMock(EntityManagerInterface::class);
        $gone->method('getClassMetadata')->willReturn($metadata);
        $gone->method('find')->willReturn(null);
        self::assertSame($user, (new TaskApiActors($gone))->managed($user));

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('getClassMetadata')->willReturn($metadata);
        $closed->method('find')->willThrowException(new RuntimeException('closed'));
        self::assertSame($user, (new TaskApiActors($closed))->managed($user));
    }
}
