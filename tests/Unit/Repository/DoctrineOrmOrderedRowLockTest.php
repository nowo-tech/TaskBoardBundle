<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmOrderedRowLock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Beacon spec 239, FR-013: the transaction of the fixed lock order. A lock conflict reaches the caller as the driver's
 * own exception (a host retries 1213 / 1205 on it), never mapped to a refusal, and the connection is left with no
 * transaction open (worker mode).
 */
final class DoctrineOrmOrderedRowLockTest extends TestCase
{
    public function testAtTheOutermostLevelTheWorkIsCommitted(): void
    {
        $connection = $this->connection(0, false);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        self::assertSame('done', $this->lock($connection)->transactional(static fn (): string => 'done'));
    }

    public function testInsideACallersTransactionItJoinsIt(): void
    {
        $connection = $this->connection(1, false);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');

        self::assertSame(7, $this->lock($connection)->transactional(static fn (): int => 7));
    }

    public function testAtTheOutermostLevelADeadlockIsRolledBackAndReachesTheCallerAsItIs(): void
    {
        $connection = $this->connection(0, false);
        $connection->expects(self::never())->method('commit');

        try {
            $this->lock($connection)->transactional(static fn (): never => throw self::deadlock());
            self::fail('The deadlock was expected.');
        } catch (DeadlockException) {
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testARollbackTheServerMadeImpossibleClosesTheConnection(): void
    {
        $connection = $this->connection(0, true);
        $connection->expects(self::once())->method('close');

        $this->expectException(DeadlockException::class);
        $this->lock($connection)->transactional(static fn (): never => throw self::deadlock());
    }

    public function testInsideACallersTransactionAFailureReachesTheCallerWithoutARollback(): void
    {
        $connection = $this->connection(1, false);
        $connection->expects(self::never())->method('rollBack');

        $this->expectException(RuntimeException::class);
        $this->lock($connection)->transactional(static fn (): never => throw new RuntimeException('boom'));
    }

    public function testNoIdsLockNothing(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('createQuery');

        $lock = new DoctrineOrmOrderedRowLock($manager);
        $lock->lockColumns([]);
        $lock->lockTasks([]);
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException {}, null);
    }

    /**
     * @return Connection&MockObject
     */
    private function connection(int $depth, bool $rollbackFails): Connection
    {
        $connection = $this->createMock(Connection::class);
        $level      = $depth;
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level): void {
            ++$level;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$level, $rollbackFails): void {
            if ($rollbackFails) {
                throw new RuntimeException('SAVEPOINT DOCTRINE_2 does not exist');
            }
            --$level;
        });

        return $connection;
    }

    private function lock(Connection $connection): DoctrineOrmOrderedRowLock
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);

        return new DoctrineOrmOrderedRowLock($manager);
    }
}
