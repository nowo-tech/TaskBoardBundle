<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Since 2.22.0 (owner decision, transaction-doctrine 1.3): a Task API write that meets a database lock conflict — a
 * deadlock or lock wait timeout anywhere in the failure's chain, a write unit's ConcurrencyConflict — answers 409 +
 * `Retry-After` with `CONFLICT_001` (was 500 `SERVER_001`), on any API route and without route-name matching. After a
 * partial commit it is `CONFLICT_002` without `Retry-After`. Without nowo-tech/transaction-doctrine's bundle registered
 * (this kernel) the fallback policy answers 409 + 1 s. The idempotency key is released: the same request may be sent again.
 */
final class ApiLockConflictTest extends ApiTestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->board          = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testADeadlockOnAnUpdateIs409WithRetryAfter(): void
    {
        $task = $this->task($this->board, 'Contended');
        $this->failWith(new RuntimeException('host decorator', 0, new DeadlockException(self::driver(), null)));

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Renamed'], ['HTTP_IDEMPOTENCY_KEY' => 'retry-me']);

        $this->assertRefused(409, 'CONFLICT_001');
        self::assertSame('1', $this->client->getResponse()->headers->get('Retry-After'));
        $this->em->clear();
        self::assertSame('Contended', $this->em->find(Task::class, $task->getId())?->getTitle());
    }

    public function testAWriteUnitThatGaveUpKeepsItsRetryAfter(): void
    {
        $this->failWith(new ConcurrencyConflict(3, TransientFailureKind::LockWaitTimeout, 2, new RuntimeException('x')));

        $this->send('POST', '/api/tasks/v2/list/' . $this->board->getId() . '/task', ['name' => 'New']);

        $this->assertRefused(409, 'CONFLICT_001');
        self::assertSame('2', $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testAConflictAfterAPartialCommitAsksForAReloadWithoutRetryAfter(): void
    {
        $task = $this->task($this->board, 'Half saved');
        $this->failWith(new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true));

        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId());

        $this->assertRefused(409, 'CONFLICT_002');
        self::assertFalse($this->client->getResponse()->headers->has('Retry-After'));
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAReadIsAnsweredTheSameWay(): void
    {
        $this->failWith(new DeadlockException(self::driver(), null));

        $this->get('/api/tasks/v2/list/' . $this->board->getId() . '/task');

        $this->assertRefused(409, 'CONFLICT_001');
    }

    /**
     * Audit SU-L1 (the probe): a TASK_CREATED listener that deadlocks after the create. The create and its assignees are one
     * transaction whose events are dispatched after the commit, so the failing listener is logged and the client gets its
     * task; the same Idempotency-Key replays that answer and writes no second task.
     */
    public function testAListenerDeadlockAfterTheCreateNeverLeadsToASecondTask(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_CREATED, static function (): never {
            throw new DeadlockException(self::driver(), null);
        });
        $path = '/api/tasks/v2/list/' . $this->board->getId() . '/task';

        $this->send('POST', $path, ['name' => 'Exactly once'], ['HTTP_IDEMPOTENCY_KEY' => 'probe-1']);
        self::assertResponseIsSuccessful();
        $this->send('POST', $path, ['name' => 'Exactly once'], ['HTTP_IDEMPOTENCY_KEY' => 'probe-1']);
        self::assertResponseIsSuccessful();

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM task_board_tasks WHERE title = 'Exactly once'"));
    }

    /**
     * Audit SU-L1: a request that committed part of its work (here a host step after the controller is chosen) and then meets
     * a deadlock answers CONFLICT_002 without Retry-After, and its idempotency key keeps that answer: the same key replays it
     * and writes nothing more.
     */
    public function testAConflictAfterACommitOfThisRequestIsConflict002AndTheKeyKeepsIt(): void
    {
        $connection = $this->em->getConnection();
        $boardId    = $this->board->getId();
        $calls      = 0;
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function () use ($connection, $boardId, &$calls): void {
            // The first request commits a host step, then deadlocks; a second one would create the task.
            if (++$calls === 1) {
                $connection->executeStatement('UPDATE task_board_boards SET name = ? WHERE id = ?', ['Touched', $boardId]);

                throw new DeadlockException(self::driver(), null);
            }
        }, -32);
        $path = '/api/tasks/v2/list/' . $boardId . '/task';

        $this->send('POST', $path, ['name' => 'Half'], ['HTTP_IDEMPOTENCY_KEY' => 'half-1']);
        $this->assertRefused(409, 'CONFLICT_002');
        self::assertFalse($this->client->getResponse()->headers->has('Retry-After'));

        $this->send('POST', $path, ['name' => 'Half'], ['HTTP_IDEMPOTENCY_KEY' => 'half-1']);
        $this->assertRefused(409, 'CONFLICT_002');
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM task_board_tasks WHERE title = 'Half'"), 'the same key replays the kept answer: no write');
        self::assertSame('Touched', $connection->fetchOne('SELECT name FROM task_board_boards WHERE id = ?', [$boardId]));
        // Another key is another request: it writes.
        $this->send('POST', $path, ['name' => 'Half'], ['HTTP_IDEMPOTENCY_KEY' => 'half-2']);
        self::assertResponseIsSuccessful();
    }

    private function failWith(Throwable $failure): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function () use ($failure): never {
            throw $failure;
        });
    }

    private static function driver(): DriverException
    {
        return new class('Deadlock found') extends RuntimeException implements DriverException {
            public function getSQLState(): string
            {
                return '40001';
            }
        };
    }
}
