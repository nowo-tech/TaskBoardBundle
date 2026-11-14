<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Nowo\TaskBoardBundle\Doctrine\TaskRevisionIncrement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit PC-L4 (2.17.0): the SQL of the database-side increment on every platform (the functional suite runs it on SQLite,
 * MySQL 8.4 and PostgreSQL 16).
 */
final class TaskRevisionIncrementTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function returningPlatforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    #[DataProvider('returningPlatforms')]
    public function testOneUpdateReturningTheValue(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('fetchOne')
            ->with('UPDATE tasks SET revision = revision + 1 WHERE id = ? RETURNING revision', ['t-1'])
            ->willReturn('7');
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(7, TaskRevisionIncrement::next($connection, 'tasks', 'revision', 'id', 't-1'));
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function mysqlPlatforms(): iterable
    {
        yield 'MySQL 8.4' => [new MySQL84Platform()];
        yield 'MariaDB' => [new MariaDBPlatform()];
    }

    #[DataProvider('mysqlPlatforms')]
    public function testMysqlAndMariadbReadTheValueFromTheConnectionsInsertId(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('executeStatement')
            ->with('UPDATE `tasks` SET `revision` = LAST_INSERT_ID(`revision` + 1) WHERE `id` = ?', ['t-1'])
            ->willReturn(1);
        $connection->expects(self::once())->method('lastInsertId')->willReturn('4');
        $connection->expects(self::never())->method('fetchOne');

        self::assertSame(4, TaskRevisionIncrement::next($connection, '`tasks`', '`revision`', '`id`', 't-1'));
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function otherPlatforms(): iterable
    {
        yield 'SQL Server' => [new SQLServerPlatform()];
        yield 'Oracle' => [new OraclePlatform()];
    }

    #[DataProvider('otherPlatforms')]
    public function testAnyOtherPlatformUpdatesThenReadsTheRowItWrote(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('executeStatement')
            ->with('UPDATE tasks SET revision = revision + 1 WHERE id = ?', ['t-1'])
            ->willReturn(1);
        $connection->expects(self::once())->method('fetchOne')
            ->with('SELECT revision FROM tasks WHERE id = ?', ['t-1'])
            ->willReturn(3);

        self::assertSame(3, TaskRevisionIncrement::next($connection, 'tasks', 'revision', 'id', 't-1'));
    }
}
