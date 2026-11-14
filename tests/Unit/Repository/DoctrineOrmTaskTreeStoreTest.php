<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskTreeStore;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Tree\TaskReparentRefusedException;
use Nowo\TaskBoardBundle\Tree\TaskTreeNode;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Beacon spec 234, T004: the rollback paths of the Doctrine store, which a single test database cannot produce: the
 * server ended the transaction (a MySQL deadlock), so rolling back to the savepoint fails.
 */
final class DoctrineOrmTaskTreeStoreTest extends TestCase
{
    public function testAtTheOutermostLevelALostLockIsAConflictAndAFailedRollbackClosesTheConnection(): void
    {
        $connection = $this->connection(0, true);
        $connection->expects(self::once())->method('close');
        $dispatched = false;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('probe', static function () use (&$dispatched): void {
            $dispatched = true;
        });
        $events = new TaskEventDeferral($dispatcher);

        try {
            $this->store($connection, $events)->transactional(static fn (): never => throw self::deadlock());
            self::fail('A conflict was expected.');
        } catch (TaskReparentRefusedException $refused) {
            self::assertSame(TaskReparentRefusedException::CONFLICT, $refused->reason);
        }
        // The deferral is balanced again (worker mode): an event now is dispatched at once, never held for a later request.
        $events->dispatchSaved(new stdClass(), 'probe');
        self::assertTrue($dispatched);
    }

    public function testInsideACallersTransactionTheDriverErrorReachesTheCallerAndTheConnectionStaysOpen(): void
    {
        $connection = $this->connection(1, true);
        $connection->expects(self::never())->method('close');

        $this->expectException(DeadlockException::class);
        $this->store($connection)->transactional(static fn (): never => throw self::deadlock());
    }

    public function testAnotherFailureReachesTheCaller(): void
    {
        $connection = $this->connection(0, false);

        $this->expectException(RuntimeException::class);
        $this->store($connection)->transactional(static fn (): never => throw new RuntimeException('boom'));
    }

    public function testATaskGoneAfterItsLockIsNotFound(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn(null);

        $this->expectException(TaskReparentRefusedException::class);
        (new DoctrineOrmTaskTreeStore($manager))->task(new TaskTreeNode('gone', 'board', null, 1));
    }

    public function testACurrentCopyIsNotRefreshed(): void
    {
        $task    = new Task(new TaskBoard('Roadmap', 'roadmap', new stdClass()), 'Story', new stdClass());
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('find')->willReturn($task);
        $manager->expects(self::never())->method('refresh');

        self::assertSame($task, (new DoctrineOrmTaskTreeStore($manager))->task(new TaskTreeNode($task->getId(), $task->getBoard()->getId(), null, $task->getRevision())));
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

    private function store(Connection $connection, ?TaskEventDeferral $events = null): DoctrineOrmTaskTreeStore
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);

        return new DoctrineOrmTaskTreeStore($manager, null, $events);
    }
}
