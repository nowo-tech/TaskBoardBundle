<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Number;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\TaskNumberReserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Spec 214, FR-003: where a reservation commits, and a first number lost to another process.
 */
final class TaskNumberReserverTest extends TestCase
{
    /** A database that is not SQLite, in a caller's transaction: the counter goes through a connection of its own. */
    public function testInsideATransactionItCommitsOnAConnectionOfItsOwn(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tb-reserver');
        $real = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $real->executeStatement('CREATE TABLE ' . TaskNumberCounter::TABLE . ' (scope_key VARCHAR(191) NOT NULL PRIMARY KEY, last_number INT NOT NULL)');

        $caller = $this->createMock(Connection::class);
        $caller->method('isTransactionActive')->willReturn(true);
        $caller->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $caller->method('getParams')->willReturn(['driver' => 'pdo_sqlite', 'path' => $file]);
        $caller->method('getConfiguration')->willReturn(new Configuration());
        $caller->expects(self::never())->method('executeStatement');
        $caller->expects(self::never())->method('insert');
        $caller->expects(self::never())->method('transactional');

        try {
            self::assertSame(['a' => 1, 'b' => 1], (new TaskNumberReserver())->reserve($caller, ['a' => 2, 'b' => 1]));
            self::assertSame(['a' => 3], (new TaskNumberReserver())->reserve($caller, ['a' => 4]));
            self::assertSame(6, (int) $real->fetchOne('SELECT last_number FROM ' . TaskNumberCounter::TABLE . " WHERE scope_key = 'a'"));
        } finally {
            $real->close();
            unlink($file);
        }
    }

    /** The second connection cannot do it (here: no table; in production: a lock the caller holds): the caller's transaction does. */
    public function testItFallsBackToTheCallersTransaction(): void
    {
        $file   = (string) tempnam(sys_get_temp_dir(), 'tb-reserver');
        $caller = $this->createMock(Connection::class);
        $caller->method('isTransactionActive')->willReturn(true);
        $caller->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $caller->method('getParams')->willReturn(['driver' => 'pdo_sqlite', 'path' => $file]);
        $caller->method('getConfiguration')->willReturn(new Configuration());
        $caller->method('transactional')->willReturnCallback(static fn (Closure $run): mixed => $run($caller));
        $caller->method('executeStatement')->willReturn(1);
        $caller->method('fetchOne')->willReturn(9);

        try {
            self::assertSame(['a' => 9], (new TaskNumberReserver())->reserve($caller, ['a' => 1]));
        } finally {
            unlink($file);
        }
    }

    /** No second connection can even be built: the caller's transaction does it. */
    public function testAnUnusableSecondConnectionFallsBackToo(): void
    {
        $caller = $this->createMock(Connection::class);
        $caller->method('isTransactionActive')->willReturn(true);
        $caller->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $caller->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'none', 'serverVersion' => '8.0.32']);
        $caller->method('getConfiguration')->willReturn(new Configuration());
        $caller->method('transactional')->willReturnCallback(static fn (Closure $run): mixed => $run($caller));
        $caller->method('executeStatement')->willReturn(1);
        $caller->method('fetchOne')->willReturn(3);

        self::assertSame(['a' => 3], (new TaskNumberReserver())->reserve($caller, ['a' => 1]));
    }

    public function testAFirstNumberLostToAnotherProcessTriesAgain(): void
    {
        $connection = $this->connection();
        $connection->method('executeStatement')->willReturnOnConsecutiveCalls(0, 1);
        $connection->method('fetchOne')->willReturn(5);
        $connection->expects(self::once())->method('insert')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        self::assertSame(['a' => 4], (new TaskNumberReserver())->reserve($connection, ['a' => 2]));
    }

    public function testItGivesUpRatherThanGuess(): void
    {
        $connection = $this->connection();
        $connection->method('executeStatement')->willReturn(0);
        $connection->expects(self::exactly(3))->method('insert')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $this->expectException(RuntimeException::class);
        (new TaskNumberReserver())->reserve($connection, ['a' => 1]);
    }

    private function connection(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('transactional')->willReturnCallback(static fn (Closure $run): mixed => $run($connection));

        return $connection;
    }
}
