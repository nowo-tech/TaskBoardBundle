<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Number;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeConflictException;
use Nowo\TaskBoardBundle\Number\TaskNumberListener;
use Nowo\TaskBoardBundle\Number\TaskNumberReserver;
use Nowo\TaskBoardBundle\Number\TaskNumberScope;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.15.1 (audit OM TB-L2): a new task whose scope counter is a look-alike scope's is refused before it is scheduled.
 */
final class TaskNumberListenerPrePersistTest extends TestCase
{
    public function testALookAlikeCounterIsRefusedAndPostgreSqlIsNotAsked(): void
    {
        $board    = (new TaskBoard('B', 'b', null))->setTenantKey('acme');
        $task     = new Task(board: $board, title: 'T', creator: new stdClass());
        $listener = new TaskNumberListener(new TaskNumberScope(), new TaskNumberReserver());

        $listener->prePersist(new PrePersistEventArgs($task, $this->manager(new PostgreSQLPlatform(), null)));
        $listener->prePersist(new PrePersistEventArgs($task, $this->manager(new SQLitePlatform(), false)));
        $listener->prePersist(new PrePersistEventArgs($task, $this->manager(new SQLitePlatform(), 'acme')));
        $listener->prePersist(new PrePersistEventArgs(new stdClass(), $this->manager(new SQLitePlatform(), null)));

        $this->expectException(TaskNumberScopeConflictException::class);
        $listener->prePersist(new PrePersistEventArgs($task, $this->manager(new SQLitePlatform(), 'Acme')));
    }

    /** @param false|string|null $stored null: the counter is never read */
    private function manager(object $platform, string|false|null $stored): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        if ($stored === null) {
            $connection->expects(self::never())->method('fetchOne');
        } else {
            $connection->method('fetchOne')->willReturn($stored);
        }
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        return $manager;
    }
}
