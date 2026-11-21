<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\Group;

use function proc_close;
use function proc_open;
use function stream_get_contents;

use const PHP_BINARY;

/**
 * Since 2.29.0 (REQ-RUNTIME-003): the host ports under real concurrency, with a second connection or a second process
 * against TASK_BOARD_TEST_DATABASE_URL (MySQL, MariaDB, PostgreSQL; run with `--group host-ports-db --fail-on-skipped`).
 *
 * - two processes adjusting one task's total never lose an adjustment (atomic UPDATE, no read-modify-write in PHP);
 * - a task row another connection holds makes the write wait, and a lock wait timeout is a transient failure that wrote
 *   nothing (retry);
 * - a revision another connection committed meanwhile is a conflict (409), and nothing is written.
 */
#[Group('host-ports-db')]
final class HostPortsConcurrencyTest extends FunctionalTestCase
{
    private ?Connection $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('Two connections need a database server (TASK_BOARD_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL).');
        }
        $this->other = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse(FunctionalKernel::databaseUrl()));
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        parent::tearDown();
    }

    public function testTwoProcessesAdjustingTheTotalNeverLoseAnAdjustment(): void
    {
        $task    = $this->commands()->createTask($this->ownerRef(), $this->board()->getId(), new TaskFormData('Timed'));
        $running = [];
        foreach ([1, 2] as $worker) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/adjust-worker.php', $task->id, '40', '7'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $running[$worker] = [$process, $pipes];
        }
        foreach ($running as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $output . $errors);
        }

        self::assertSame(560, (int) $this->other()->fetchOne('SELECT total_time_seconds FROM task_board_tasks WHERE id = ?', [$task->id]));
    }

    public function testALockedTaskRowIsATransientFailureThatWritesNothing(): void
    {
        $task  = $this->commands()->createTask($this->ownerRef(), $this->board()->getId(), new TaskFormData('Locked'));
        $other = $this->other();
        $this->shortLockWait();

        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$task->id]);
        try {
            $this->commands()->updateTask($this->ownerRef(), $task->id, new TaskFormData('Blocked'));
            self::fail('The locked row must not be written.');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $failure->getMessage());
        } finally {
            $other->commit();
        }

        self::assertSame('Locked', $this->query()->findTaskById($task->id)?->title);
        self::assertSame('Unblocked', $this->commands()->updateTask($this->ownerRef(), $task->id, new TaskFormData('Unblocked'))->title, 'the retry succeeds');
    }

    public function testARevisionCommittedByAnotherConnectionIsAConflict(): void
    {
        $task = $this->commands()->createTask($this->ownerRef(), $this->board()->getId(), new TaskFormData('Shared'));
        $this->other()->executeStatement('UPDATE task_board_tasks SET title = ?, revision = revision + 1 WHERE id = ?', ['Theirs', $task->id]);

        try {
            $this->commands()->updateTask($this->ownerRef(), $task->id, new TaskFormData('Mine'), $task->revision);
            self::fail('A stale revision must be refused.');
        } catch (TaskChangedException $changed) {
            self::assertSame($task->revision + 1, $changed->currentRevision);
        }
        $this->em->clear();
        self::assertSame('Theirs', $this->query()->findTaskById($task->id)?->title);
    }

    private function shortLockWait(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    }

    private function other(): Connection
    {
        self::assertInstanceOf(Connection::class, $this->other);

        return $this->other;
    }

    private function commands(): TaskCommandsInterface
    {
        return self::getContainer()->get(TaskCommandsInterface::class);
    }

    private function query(): TaskBoardQueryInterface
    {
        return self::getContainer()->get(TaskBoardQueryInterface::class);
    }

    private function ownerRef(): UserRef
    {
        return UserRef::ofId((string) $this->owner->getId());
    }
}
