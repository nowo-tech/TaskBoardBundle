<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Since 1.23: an actor id the user class cannot load (a lookup error) falls back to the user who linked the board.
 */
final class ConnectionCreatorActorResolverTest extends TestCase
{
    public function testALookupErrorFallsBackToTheConnection(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willThrowException(new RuntimeException('bad id'));
        $entityManager->method('getRepository')->willReturn($repository);

        $resolver = new ConnectionCreatorActorResolver($entityManager, stdClass::class);
        self::assertNull($resolver->actorFor(new TaskBoard('B', 'b', new stdClass()), 'x'));
    }
}
