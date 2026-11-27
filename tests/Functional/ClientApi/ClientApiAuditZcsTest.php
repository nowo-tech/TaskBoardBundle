<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function base64_decode;
use function strtr;

/**
 * Since 3.4.0, audit ZCS: sealed cursors and visible-only counts (L2), the lock order of a checklist tick (L3), the
 * revision in the idempotency fingerprint (I3), the host's active-task limit on a restore and a reopen (I7).
 */
final class ClientApiAuditZcsTest extends ClientApiTestCase
{
    private TaskBoard $roadmap;

    private ?Connection $other = null;

    protected function setUp(): void
    {
        TestSyncLimitChecker::reset();
        self::$hostConfig = ['sync' => ['enabled' => true, 'credential_resolver' => 'app.sync_credentials', 'attachment_storage' => 'app.sync_storage', 'limit_checker' => 'app.sync_limits']];
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
    }

    protected function tearDown(): void
    {
        TestSyncLimitChecker::reset();
        $this->other?->close();
        $this->other = null;
        parent::tearDown();
    }

    public function testAHiddenTaskIsNeitherInACursorNorInACount(): void
    {
        $this->task($this->roadmap, 'Shown', 0, null, 0);
        $hidden = $this->task($this->roadmap, 'Hidden', 0, null, 1);
        $this->task($this->roadmap, 'Last', 0, null, 2);
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($hidden): void {
            if ($event->getTaskId() === $hidden->getId()) {
                $event->deny();
            }
        });
        $column = $this->roadmap->getColumns()->get(0);
        self::assertNotNull($column);

        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $column->getId() . '&limit=2');
        self::assertSame(['Shown'], array_column($this->json()['tasks'], 'title'), 'the hidden row was scanned, not shown');
        $cursor = (string) $this->json()['nextCursor'];
        self::assertStringNotContainsString($hidden->getId(), (string) base64_decode(strtr($cursor, '-_', '+/'), true));
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $column->getId() . '&limit=2&cursor=' . $cursor);
        self::assertSame(['Last'], array_column($this->json()['tasks'], 'title'));

        // A changed cursor is refused.
        $cursor[10] = $cursor[10] === 'A' ? 'B' : 'A';
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $column->getId() . '&cursor=' . $cursor);
        $this->assertError(400, 'invalid_cursor');

        $this->call('GET', '/boards/' . $this->roadmap->getId());
        self::assertSame(['open' => 2, 'capped' => false], $this->json()['board']['counts']);
        $this->call('GET', '/boards');
        self::assertArrayNotHasKey('counts', $this->json()['boards'][0]);
    }

    public function testATickLocksItsColumnFirstAndLosesToAWebMove(): void
    {
        $parent = $this->task($this->roadmap, 'Launch');
        $this->call('POST', '/tasks/' . $parent->getId() . '/checklist-items', ['checklist' => 'Go', 'title' => 'DNS'], ['HTTP_IDEMPOTENCY_KEY' => 'item']);
        $itemId = (string) $this->json()['itemId'];

        // The web board drags the item into Done on another connection.
        $other = $this->otherWriter();
        $item  = $other->find(Task::class, $itemId);
        $done  = $other->find(TaskBoard::class, $this->roadmap->getId())?->getColumns()->get(2);
        self::assertInstanceOf(Task::class, $item);
        self::assertNotNull($done);
        $item->setColumn($done)->markCompleted();
        $other->flush();

        $this->call('PATCH', '/tasks/' . $parent->getId() . '/checklist-items/' . $itemId, ['done' => true], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(409, 'conflict');
        $this->call('PATCH', '/tasks/' . $parent->getId() . '/checklist-items/' . $itemId, ['done' => false], ['HTTP_IF_MATCH' => '"' . $this->json()['currentRevision'] . '"']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertFalse($this->json()['task']['checklists'][0]['items'][0]['done']);
        // A rename alone enters no column.
        $this->call('PATCH', '/tasks/' . $parent->getId() . '/checklist-items/' . $itemId, ['title' => 'DNS records'], ['HTTP_IF_MATCH' => '"' . $this->json()['itemRevision'] . '"']);
        self::assertResponseIsSuccessful();
    }

    public function testAContextWithoutBoardsHasNoTasksAndNoChanges(): void
    {
        $this->call('GET', '/my-tasks', context: 'org:acme');
        self::assertSame([], $this->json()['tasks']);
        $this->call('GET', '/changes', context: 'org:acme');
        $this->call('GET', '/changes?since=' . $this->json()['nextCursor'], context: 'org:acme');
        self::assertSame([], $this->json()['changes']);
    }

    public function testARetryWithAnotherRevisionIsAnotherRequest(): void
    {
        $task = $this->task($this->roadmap, 'Keyed');
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'One'], ['HTTP_IF_MATCH' => '"1"', 'HTTP_IDEMPOTENCY_KEY' => 'same']);
        self::assertResponseIsSuccessful();
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'One'], ['HTTP_IF_MATCH' => '"2"', 'HTTP_IDEMPOTENCY_KEY' => 'same']);
        $this->assertError(409, 'idempotency_mismatch');
    }

    public function testTheHostsActiveTaskLimitOnARestoreAndAReopen(): void
    {
        $task = $this->task($this->roadmap, 'Archived');
        $this->call('PATCH', '/tasks/' . $task->getId(), ['archived' => true], ['HTTP_IF_MATCH' => '"1"']);
        $revision                          = $this->json()['task']['revision'];
        TestSyncLimitChecker::$refuseTasks = true;
        $this->call('PATCH', '/tasks/' . $task->getId(), ['archived' => false], ['HTTP_IF_MATCH' => '"' . $revision . '"']);
        $this->assertError(403, 'plan_limit');
        self::assertSame('limit', $this->json()['reason']);

        // Moving a completed task out of the done column reopens it: the same limit.
        TestSyncLimitChecker::$refuseTasks = false;
        $done                              = $this->task($this->roadmap, 'Finished', 2);
        $fresh                             = $this->em->find(Task::class, $done->getId());
        self::assertInstanceOf(Task::class, $fresh);
        $fresh->markCompleted();
        $this->em->flush();
        $todo = $this->roadmap->getColumns()->get(0);
        self::assertNotNull($todo);
        TestSyncLimitChecker::$refuseTasks = true;
        $this->call('POST', '/tasks/' . $done->getId() . '/move', ['columnId' => $todo->getId()], ['HTTP_IF_MATCH' => '"' . $fresh->getRevision() . '"']);
        $this->assertError(403, 'plan_limit');
    }

    private function otherWriter(): EntityManager
    {
        $connection = $this->em->getConnection();
        $params     = $connection->getParams();
        if (($params['memory'] ?? false) !== true && ($params['path'] ?? null) !== ':memory:') {
            $connection = $this->other = DriverManager::getConnection($params, $connection->getConfiguration());
        }

        return new EntityManager($connection, $this->em->getConfiguration(), $this->em->getEventManager());
    }
}
