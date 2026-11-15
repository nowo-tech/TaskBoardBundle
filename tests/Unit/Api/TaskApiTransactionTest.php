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
    /**
     * Audit PX-M1 (since 2.16.0): MySQL ended the transaction on a deadlock, so the rollback to the savepoint fails. The
     * connection is closed (its nesting level back to 0), the clean-up still runs, and the write's failure is rethrown.
     */
    public function testAFailedRollbackClosesTheConnectionAndStillCleansUp(): void
    {
        $task       = new Task(new TaskBoard('Roadmap', 'roadmap', null), 'Touched', null);
        $connection = $this->createMock(Connection::class);
        $level      = 0;
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level): void {
            $level += 2; // as after a nested savepoint the server no longer has
        });
        $connection->method('rollBack')->willThrowException(new RuntimeException('SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects(self::once())->method('close')->willReturnCallback(static function () use (&$level): void {
            $level = 0;
        });
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('contains')->willReturn(true);
        $refreshed = [];
        $manager->method('refresh')->willReturnCallback(static function (object $entity) use (&$refreshed): void {
            $refreshed[] = $entity;
        });

        try {
            (new TaskApiTransaction($manager))->run(static fn (): never => throw new RuntimeException('deadlock'), [$task]);
            self::fail('No failure');
        } catch (RuntimeException $failure) {
            self::assertSame('deadlock', $failure->getMessage());
        }
        self::assertSame([$task], $refreshed);
        self::assertSame(0, $level);
    }

    public function testAFailedWriteRollsBackAndReloadsWhatItTouched(): void
    {
        $task       = new Task(new TaskBoard('Roadmap', 'roadmap', null), 'Touched', null);
        $gone       = new Task(new TaskBoard('Roadmap', 'roadmap-2', null), 'Gone', null);
        $connection = $this->createMock(Connection::class);
        $level      = 0;
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->expects(self::once())->method('beginTransaction')->willReturnCallback(static function () use (&$level): void {
            ++$level;
        });
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack')->willReturnCallback(static function () use (&$level): void {
            --$level;
        });
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
