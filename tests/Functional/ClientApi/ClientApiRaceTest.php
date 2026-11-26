<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;

use function array_column;

/**
 * Since 3.4.0 (REQ-RUNTIME-003, §8): two writers on two connections (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in CI;
 * the same in-memory SQLite connection otherwise). A device that loaded a task before another device (or the web board)
 * committed is told `409 conflict` with the current revision and writes nothing; the revision on the locked row decides,
 * never one computed in PHP; a refused write leaves no audit event and the next request of the process works.
 */
final class ClientApiRaceTest extends ClientApiTestCase
{
    private TaskBoard $roadmap;

    private ?Connection $otherConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
    }

    protected function tearDown(): void
    {
        $this->otherConnection?->close();
        $this->otherConnection = null;
        parent::tearDown();
    }

    public function testAnUnlockedWriteOnAnotherConnectionMakesTheDevicesRevisionStale(): void
    {
        $task  = $this->task($this->roadmap, 'Raced');
        $other = $this->otherWriter();
        $stale = $other->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $stale);

        // The web board (another connection, no lock) saves first.
        $stale->setDescription('From the web');
        $other->flush();
        self::assertSame(2, $this->revisionInDatabase($task));

        // The device still holds revision 1: refused, nothing written, no audit.
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'From the desk'], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(409, 'conflict');
        self::assertSame(2, $this->json()['currentRevision']);
        self::assertSame([], $this->audit()->events);
        self::assertSame('Raced', $this->em->getConnection()->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$task->getId()]));

        // Read again, write again: the process is fine and the revision moves once.
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'From the desk'], ['HTTP_IF_MATCH' => '"2"']);
        self::assertResponseIsSuccessful();
        self::assertSame([3, 'From the desk', 'From the web'], [$this->json()['task']['revision'], $this->json()['task']['title'], $this->em->getConnection()->fetchOne('SELECT description FROM task_board_tasks WHERE id = ?', [$task->getId()])]);
    }

    public function testTwoDevicesMovingTheSameTaskOnlyOneWins(): void
    {
        $task  = $this->task($this->roadmap, 'Moved twice');
        $doing = $this->roadmap->getColumns()->get(1);
        $done  = $this->roadmap->getColumns()->get(2);
        self::assertNotNull($doing);
        self::assertNotNull($done);

        $this->call('POST', '/tasks/' . $task->getId() . '/move', ['columnId' => $doing->getId()], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful();
        $this->call('POST', '/tasks/' . $task->getId() . '/move', ['columnId' => $done->getId()], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(409, 'conflict');

        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $doing->getId());
        self::assertSame(['Moved twice'], array_column($this->json()['tasks'], 'title'));
    }

    public function testAChecklistTickOfAStaleItemIsRefused(): void
    {
        $parent = $this->task($this->roadmap, 'Parent');
        $this->call('POST', '/tasks/' . $parent->getId() . '/checklist-items', ['checklist' => 'Go', 'title' => 'One'], ['HTTP_IDEMPOTENCY_KEY' => 'race-item']);
        $itemId = (string) $this->json()['itemId'];
        $other  = $this->otherWriter();
        $item   = $other->find(Task::class, $itemId);
        self::assertInstanceOf(Task::class, $item);
        $item->setTitle('Renamed on the web');
        $other->flush();

        $this->call('PATCH', '/tasks/' . $parent->getId() . '/checklist-items/' . $itemId, ['done' => true], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(409, 'conflict');
        self::assertSame(2, $this->json()['currentRevision']);
    }

    private function audit(): ApiAuditRecorder
    {
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);

        return $audit;
    }

    private function otherWriter(): EntityManagerInterface
    {
        $connection = $this->em->getConnection();
        $params     = $connection->getParams();
        if (($params['memory'] ?? false) !== true && ($params['path'] ?? null) !== ':memory:') {
            $connection = $this->otherConnection = DriverManager::getConnection($params, $connection->getConfiguration());
        }

        return new EntityManager($connection, $this->em->getConfiguration(), $this->em->getEventManager());
    }

    private function revisionInDatabase(Task $task): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT revision FROM task_board_tasks WHERE id = ?', [$task->getId()]);
    }
}
