<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Nowo\TaskBoardBundle\Doctrine\TransactionRollback;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Audits PX-M1 / PX-L6: the shared rollback rolls back to the entry level, and closes the connection only when that
 * fails at the outermost level.
 */
final class TransactionRollbackTest extends TestCase
{
    public function testItRollsBackEveryLevelAboveTheEntryLevel(): void
    {
        $level      = 3;
        $connection = $this->connection($level, false);
        $connection->expects(self::never())->method('close');

        TransactionRollback::toLevel($connection, 1);

        self::assertSame(1, $level);
    }

    public function testAFailedRollbackAtTheOutermostLevelClosesTheConnection(): void
    {
        $level      = 2;
        $connection = $this->connection($level, true);
        $connection->expects(self::once())->method('close')->willReturnCallback(static function () use (&$level): void {
            $level = 0;
        });

        TransactionRollback::toLevel($connection, 0);

        self::assertSame(0, $level);
    }

    public function testInsideACallersTransactionAFailedRollbackIsLeftToTheCaller(): void
    {
        $level      = 2;
        $connection = $this->connection($level, true);
        $connection->expects(self::never())->method('close');

        TransactionRollback::toLevel($connection, 1);

        self::assertSame(2, $level);
    }

    private function connection(int &$level, bool $fails): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$level, $fails): void {
            if ($fails) {
                throw new RuntimeException('SAVEPOINT DOCTRINE_2 does not exist');
            }
            --$level;
        });

        return $connection;
    }
}
