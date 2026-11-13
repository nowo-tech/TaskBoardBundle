<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\Hook\EmptyTaskApiMembers;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTransaction;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class TaskApiTransactionTest extends TestCase
{
    public function testAFailedWriteRollsBackAndReloadsWhatItTouched(): void
    {
        $task       = new Task(new TaskBoard('Roadmap', 'roadmap', null), 'Touched', null);
        $gone       = new Task(new TaskBoard('Roadmap', 'roadmap-2', null), 'Gone', null);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('contains')->willReturnCallback(static fn (object $entity): bool => $entity !== $gone || true);
        $refreshed = [];
        $manager->method('refresh')->willReturnCallback(static function (object $entity) use (&$refreshed, $gone): void {
            if ($entity === $gone) {
                throw new RuntimeException('row deleted');
            }
            $refreshed[] = $entity;
        });

        try {
            (new TaskApiTransaction($manager))->run(static fn (): never => throw new RuntimeException('database down'), [$task, $gone]);
            self::fail('No failure');
        } catch (RuntimeException $failure) {
            self::assertSame('database down', $failure->getMessage());
        }
        self::assertSame([$task], $refreshed);
    }

    public function testTheMembersHookIsEmptyByDefault(): void
    {
        self::assertSame([], (new EmptyTaskApiMembers())->members(new TaskApiPrincipal(new stdClass(), TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name'), 'team-1'));
    }
}
