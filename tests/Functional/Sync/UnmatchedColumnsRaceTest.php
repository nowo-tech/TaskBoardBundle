<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Status\UnmatchedColumnsCleaner;
use ReflectionMethod;
use Throwable;

/**
 * Since 1.28.0 (audit HI-M1): a task written while a column is being deleted never loses its column. A second connection
 * writes at the moment between the cleaner's lock and its count. On PostgreSQL a no-op `UPDATE` did not stop it, so the
 * delete's `ON DELETE SET NULL` emptied the task's column.
 */
final class UnmatchedColumnsRaceTest extends SyncPeopleFunctionalTestCase
{
    public function testAMoveWhileTheColumnIsDeletedIsRefusedAndTheTaskKeepsItsColumn(): void
    {
        [$other, $spare, $taskId, $writer] = $this->scene();
        $tasks                             = $this->em->getClassMetadata(Task::class);
        $refused                           = false;
        HookedConnection::$beforeCount     = static function () use ($writer, $tasks, $spare, $taskId, &$refused): void {
            try {
                $writer->executeStatement('UPDATE ' . $tasks->getTableName() . ' SET column_id = ? WHERE id = ?', [$spare->getId(), $taskId]);
            } catch (Throwable) {
                $refused = true;
            }
        };

        self::assertTrue($this->delete($spare));
        self::assertTrue($refused, 'the writer waits on the lock');
        self::assertSame($other->getId(), $writer->fetchOne('SELECT column_id FROM ' . $tasks->getTableName() . ' WHERE id = ?', [$taskId]));
        self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM ' . $tasks->getTableName() . ' WHERE column_id IS NULL'));
    }

    public function testAnInsertWhileTheColumnIsDeletedIsRefused(): void
    {
        [, $spare, $taskId, $writer]   = $this->scene();
        $tasks                         = $this->em->getClassMetadata(Task::class);
        $refused                       = false;
        HookedConnection::$beforeCount = static function () use ($writer, $tasks, $spare, $taskId, &$refused): void {
            $row = $writer->fetchAssociative('SELECT * FROM ' . $tasks->getTableName() . ' WHERE id = ?', [$taskId]);
            self::assertIsArray($row);
            $row['id']        = 'race-task';
            $row['column_id'] = $spare->getId();

            try {
                $writer->insert($tasks->getTableName(), $row);
            } catch (Throwable) {
                $refused = true;
            }
        };

        self::assertTrue($this->delete($spare));
        self::assertTrue($refused);
        self::assertSame(0, (int) $writer->fetchOne('SELECT COUNT(*) FROM ' . $tasks->getTableName() . ' WHERE column_id IS NULL'));
    }

    public function testAWriterAlreadyOpenWinsAndTheColumnStays(): void
    {
        [, $spare, $taskId, $writer] = $this->scene();
        $tasks                       = $this->em->getClassMetadata(Task::class);
        $writer->beginTransaction();
        $writer->executeStatement('UPDATE ' . $tasks->getTableName() . ' SET column_id = ? WHERE id = ?', [$spare->getId(), $taskId]);

        $failed = false;

        try {
            $this->delete($spare);
        } catch (Throwable) {
            // The lock wait timed out: the cleaner reports the column as kept.
            $failed = true;
        }
        $writer->commit();

        self::assertTrue($failed);
        self::assertSame($spare->getId(), $writer->fetchOne('SELECT column_id FROM ' . $tasks->getTableName() . ' WHERE id = ?', [$taskId]));
    }

    /**
     * @return array{0: BoardColumn, 1: BoardColumn, 2: string, 3: Connection}
     */
    private function scene(): array
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            self::markTestSkipped('One writer at a time: there is no second connection to race with.');
        }
        $board   = $this->boardWithColumns('Other', 'Spare');
        $columns = [];
        foreach ($board->getColumns() as $column) {
            $columns[$column->getName()] = $column;
        }
        $task = new Task(board: $board, title: 'Moved', creator: $this->owner, column: $columns['Other']);
        $this->em->persist($task);
        $this->em->flush();

        return [$columns['Other'], $columns['Spare'], $task->getId(), $this->connection(Connection::class)];
    }

    private function delete(BoardColumn $column): bool
    {
        $hooked = $this->connection(HookedConnection::class);
        $em     = new class($this->em, $hooked) extends EntityManagerDecorator {
            public function __construct(EntityManagerInterface $wrapped, private readonly Connection $hooked)
            {
                parent::__construct($wrapped);
            }

            public function getConnection(): Connection
            {
                return $this->hooked;
            }
        };
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);

        return (bool) (new ReflectionMethod(UnmatchedColumnsCleaner::class, 'deleteIfEmpty'))->invoke(new UnmatchedColumnsCleaner($em, $checker), $column);
    }

    /**
     * @param class-string<Connection> $class
     */
    private function connection(string $class): Connection
    {
        $main       = $this->em->getConnection();
        $params     = $main->getParams();
        $connection = DriverManager::getConnection(['wrapperClass' => $class] + $params);
        $connection->executeStatement($main->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '500ms'");

        return $connection;
    }
}
