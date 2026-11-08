<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Move;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Events;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Move\TaskMovePlan;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use RuntimeException;
use Throwable;

use function str_starts_with;

/**
 * Audit IC-I9r (TaskBoard 2.3.1): every lock conflict of a move ends as the `stale` refusal (PostgreSQL `lock_timeout`,
 * a MySQL deadlock raised during the flush), and a failure after the move started to change the entities closes the
 * entity manager, so nothing half-applied can be flushed later in the request.
 */
final class MoveLockFailureTest extends CopyTestCase
{
    public function testAPostgreSqlLockTimeoutIsChangedSince(): void
    {
        // IC-I9r (a): Doctrine DBAL does not map SQLSTATE 55P03 (lock_not_available); the mover does.
        [$task, $target, $plan, $source] = $this->movable();
        $this->failDuringFlush(new DriverException(self::driverException('canceling statement due to lock timeout', '55P03'), null));

        $this->assertRefused('stale', fn (): TaskMovePlan => $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner));
        self::assertSame($source->getId(), $this->em->getConnection()->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }

    public function testASavepointLostToTheServerIsChangedSince(): void
    {
        // IC-I9r (b): InnoDB ended the transaction, so the flush's ROLLBACK TO SAVEPOINT fails with 1305.
        [$task, $target, $plan] = $this->movable();
        $this->failDuringFlush(new DriverException(self::driverException('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null));

        $this->assertRefused('stale', fn (): TaskMovePlan => $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner));
    }

    public function testOtherDriverErrorsStillReachTheCaller(): void
    {
        foreach ([['relation does not exist', '42P01', 0], ['FUNCTION host_fn does not exist', '42000', 1305]] as [$message, $state, $code]) {
            [$task, $target, $plan] = $this->movable();
            $this->failDuringFlush(new DriverException(self::driverException($message, $state, $code), null));
            try {
                $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
                self::fail('The driver error must reach the caller.');
            } catch (DriverException $exception) {
                self::assertStringEndsWith($message, $exception->getMessage());
            }
            self::ensureKernelShutdown();
            $this->setUp();
        }
    }

    public function testADeadlockThatEndedTheTransactionDuringTheFlush(): void
    {
        // IC-I9r (b), the real sequence: the server rolls the whole transaction back and the driver reports the deadlock;
        // the flush's ROLLBACK TO SAVEPOINT then fails. MySQL answers 1305 (→ stale); other platforms' errors are not
        // a lock conflict and reach the caller. Either way the connection is left with no transaction open and the
        // entity manager closed.
        [$task, $target, $plan, $source] = $this->movable();
        $connection                      = $this->em->getConnection();
        $this->em->getEventManager()->addEventListener(Events::postUpdate, new class($connection) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection)
            {
            }

            public function postUpdate(): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $this->connection->executeStatement('ROLLBACK');

                throw new DeadlockException(MoveLockFailureTest::driverException('Deadlock found when trying to get lock', '40001', 1213), null);
            }
        });

        $mysql = str_starts_with(FunctionalKernel::databaseUrl(), 'mysql');
        try {
            $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
            self::fail('The move must not succeed.');
        } catch (TaskCopyRefusedException $refusal) {
            self::assertTrue($mysql, 'only MySQL answers 1305');
            self::assertSame('stale', $refusal->reason);
        } catch (DbalException $failure) {
            self::assertFalse($mysql, $failure->getMessage());
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertFalse($this->em->isOpen());
        if (!str_starts_with(FunctionalKernel::databaseUrl(), 'sqlite')) {
            // The connection reconnects (an in-memory SQLite database does not survive that).
            self::assertSame($source->getId(), $connection->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
        }
    }

    public function testALockFailureAfterTheMoveStartedClosesTheEntityManager(): void
    {
        // IC-I9r (c): a lock failure inside apply(), before the flush (in practice the sync-off DBAL tombstone UPDATE),
        // leaves the entities changed in memory. The entity manager is closed, so a later flush cannot write them.
        foreach ([new LockWaitTimeoutException(self::driverException('lock wait timeout'), null), new RuntimeException('host listener failed')] as $failure) {
            [$task, $target, $plan, $source] = $this->movable();
            $mover                           = $this->moverFailingOnSave($failure);
            try {
                $mover->move($task, $target, [], $plan->fingerprint, $this->owner);
                self::fail('The move must not succeed.');
            } catch (TaskCopyRefusedException $refusal) {
                self::assertInstanceOf(LockWaitTimeoutException::class, $failure);
                self::assertSame('stale', $refusal->reason);
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }
            self::assertSame($target->getId(), $task->getBoard()->getId(), 'the move was applied in memory');
            self::assertFalse($this->em->isOpen());
            try {
                $this->em->flush();
                self::fail('A later flush must not write the half-applied move.');
            } catch (EntityManagerClosed) {
            }
            self::assertSame($source->getId(), $this->em->getConnection()->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
            self::ensureKernelShutdown();
            $this->setUp();
        }
    }

    public function testARowLockedByAnotherConnectionIsChangedSinceAndKeepsTheEntityManagerOpen(): void
    {
        // IC-I9r (a), real lock timeout: MySQL 1205 and PostgreSQL 55P03 (`lock_timeout`), both in lockTree(), before
        // anything changed in memory, so the entity manager stays usable for the new preview.
        $url = FunctionalKernel::databaseUrl();
        if (str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('Row locks need MySQL or PostgreSQL.');
        }
        [$task, $target, $plan, $source] = $this->movable();

        $mysql = str_starts_with($url, 'mysql');
        $other = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$task->getId()]);
        $connection = $this->em->getConnection();
        $connection->executeStatement($mysql ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '300ms'");
        try {
            $this->assertRefused('stale', fn (): TaskMovePlan => $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner));
            self::assertTrue($this->em->isOpen());
            self::assertSame(0, $connection->getTransactionNestingLevel());
        } finally {
            $other->rollBack();
            $other->close();
            $connection->executeStatement($mysql ? 'SET SESSION innodb_lock_wait_timeout = DEFAULT' : 'SET lock_timeout = DEFAULT');
        }
        self::assertSame($source->getId(), $connection->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
    }

    public static function driverException(string $message, ?string $sqlState = null, int $code = 0): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }

    /**
     * @return array{0: Task, 1: TaskBoard, 2: TaskMovePlan, 3: TaskBoard}
     */
    private function movable(): array
    {
        $source = $this->board('Source');
        $target = $this->board('Target');
        $task   = $this->task($source, 'Moves');
        $this->task($source, 'Sub', 0, $task);

        return [$task, $target, $this->planner()->plan($task, $target), $source];
    }

    private function failDuringFlush(Throwable $failure): void
    {
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class($failure) {
            public function __construct(private readonly Throwable $failure)
            {
            }

            public function onFlush(): never
            {
                throw $this->failure;
            }
        });
    }

    private function moverFailingOnSave(Throwable $failure): TaskMover
    {
        $repository = self::getContainer()->get(TaskRepositoryInterface::class);
        self::assertInstanceOf(TaskRepositoryInterface::class, $repository);

        return new TaskMover($this->planner(), $this->em, new class($repository, $failure) implements TaskRepositoryInterface {
            public function __construct(private readonly TaskRepositoryInterface $inner, private readonly Throwable $failure)
            {
            }

            public function save(Task $task): never
            {
                throw $this->failure;
            }

            public function findById(string $id): ?Task
            {
                return $this->inner->findById($id);
            }

            public function findByBoard(TaskBoard $board, bool $includeCompleted = false): array
            {
                return $this->inner->findByBoard($board, $includeCompleted);
            }

            public function findTrackableForUser(string $userId, ?string $search, int $limit, int $offset): array
            {
                return $this->inner->findTrackableForUser($userId, $search, $limit, $offset);
            }

            public function findByHostObject(string $objectType, string $objectId): array
            {
                return $this->inner->findByHostObject($objectType, $objectId);
            }
        }, new TaskChangeRecorder());
    }
}
