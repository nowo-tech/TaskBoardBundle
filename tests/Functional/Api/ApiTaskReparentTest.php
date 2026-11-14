<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function end;
use function method_exists;
use function sort;

/**
 * Beacon spec 234, T004 (TaskBoard 2.20.0): `parent` on `PUT /v2/task/{id}` (MCP `task_update`) re-parents a task or
 * detaches it (null), through the domain service every caller shares (`Tree\TaskReparenter`).
 */
final class ApiTaskReparentTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        PathWriteProvenance::reset();
        self::$hostConfig = ['write_provenance_provider' => 'app.write_provenance'];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    protected function tearDown(): void
    {
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    public function testATaskMovesUnderAnotherParentWithItsSubtree(): void
    {
        PathWriteProvenance::$label = 'CI token';
        $parent                     = $this->task($this->roadmap, 'Epic');
        $task                       = $this->task($this->roadmap, 'Story');
        $child                      = $this->task($this->roadmap, 'Sub', parent: $task);
        $grandChild                 = $this->task($this->roadmap, 'Sub-sub', parent: $child);
        $updated                    = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$updated): void {
            $updated[] = $event;
        });

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame($parent->getId(), $answer['parent']);
        self::assertSame(2, $answer['revision'], 'one new revision');
        self::assertResponseHeaderSame('ETag', '"2"');
        self::assertArrayNotHasKey('warnings', $answer, '`parent` is read, no longer not_supported');
        self::assertSame($parent->getId(), $this->parentInDatabase($task));
        self::assertSame($task->getId(), $this->parentInDatabase($child), 'the subtree moves with it');
        self::assertSame($child->getId(), $this->parentInDatabase($grandChild));

        $history = $this->parentHistory($task);
        self::assertCount(1, $history);
        self::assertNull($history[0]->getOldValue());
        self::assertSame('Epic', $history[0]->getNewValue());
        self::assertSame('task:' . $parent->getId(), $history[0]->getContext());
        self::assertSame(WriteChannel::Api, $history[0]->getChannel(), 'the provenance of the write');
        self::assertSame('CI token', $history[0]->getActorLabel());
        self::assertSame($this->owner->getId(), $this->userIdOf($history[0]));
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame(['api', 'CI token'], [$this->json()['channel'], $this->json()['token_name']], 'the last write of the task');

        $event = end($this->audit()->events);
        self::assertNotFalse($event);
        self::assertSame(['task.update', ['parent' => $parent->getId()], ['parent']], [$event->operation, $event->targetIds, $event->changedFields]);
        self::assertCount(1, $updated);
        self::assertSame(['parent'], $updated[0]->getChangedFields());
        self::assertNull($updated[0]->getPreviousValue('parent'));
    }

    public function testNullDetachesTheTaskToTheTopLevel(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story', parent: $parent);
        $child  = $this->task($this->roadmap, 'Sub', parent: $task);

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => null]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['parent']);
        self::assertNull($this->parentInDatabase($task));
        self::assertSame($task->getId(), $this->parentInDatabase($child));
        $history = $this->parentHistory($task);
        self::assertCount(1, $history);
        self::assertSame(['Epic', null, null], [$history[0]->getOldValue(), $history[0]->getNewValue(), $history[0]->getContext()]);
        $event = end($this->audit()->events);
        self::assertNotFalse($event);
        self::assertSame([[], ['parent']], [$event->targetIds, $event->changedFields]);
    }

    public function testTheSameParentWritesNothing(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story', parent: $parent);

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);
        self::assertResponseIsSuccessful();
        $this->send('PUT', '/api/tasks/v2/task/' . $parent->getId(), ['parent' => null]);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->json()['revision']);
        self::assertSame([], $this->parentHistory($task));
        self::assertSame([], $this->parentHistory($parent));
        self::assertSame([], end($this->audit()->events)->changedFields ?? null);
    }

    public function testAParentOnAnotherBoardIsRefused(): void
    {
        $task  = $this->task($this->roadmap, 'Story');
        $other = $this->task($this->board('Other', tenantKey: 'tenant-a'), 'Elsewhere');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $other->getId(), 'name' => 'Renamed']);

        $this->assertRefused(400, 'PARENT_001');
        $this->assertNothingWritten($task, 'Story');
    }

    public function testATaskIsNeverItsOwnParent(): void
    {
        $task = $this->task($this->roadmap, 'Story');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $task->getId()]);

        $this->assertRefused(400, 'PARENT_002');
        $this->assertNothingWritten($task, 'Story');
    }

    public function testADescendantAtAnyDepthIsRefused(): void
    {
        $task       = $this->task($this->roadmap, 'Story');
        $child      = $this->task($this->roadmap, 'Sub', parent: $task);
        $grandChild = $this->task($this->roadmap, 'Sub-sub', parent: $child);

        foreach ([$child, $grandChild] as $descendant) {
            $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $descendant->getId(), 'name' => 'Renamed']);
            $this->assertRefused(409, 'PARENT_003');
        }
        $this->assertNothingWritten($task, 'Story');
    }

    /**
     * The tree is read from the committed rows under lock, never from the identity map: another process put the parent
     * under the task (this kept-alive manager still holds the parent at the top level); the move would close a cycle.
     */
    public function testTheCycleCheckReadsTheCommittedRowsNotTheIdentityMap(): void
    {
        $task   = $this->task($this->roadmap, 'Story');
        $parent = $this->task($this->roadmap, 'Epic');
        $other  = $this->task($this->roadmap, 'Other epic');
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET parent_id = ? WHERE id = ?', [$task->getId(), $parent->getId()]);
        self::assertNull($parent->getParent(), 'the identity map is behind');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);

        $this->assertRefused(409, 'PARENT_003');
        self::assertNull($this->parentInDatabase($task));

        // The other way round is allowed, and the stale copy of the task is refreshed before it is written.
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET parent_id = NULL WHERE id = ?', [$parent->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET parent_id = ? WHERE id = ?', [$parent->getId(), $task->getId()]);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $other->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Epic', 'Other epic'], [$this->parentHistory($task)[0]->getOldValue(), $this->parentHistory($task)[0]->getNewValue()]);
    }

    /**
     * There is no depth limit: create, the board UI and imports nest subtasks at any depth, and so does a move. A chain of
     * 30 levels takes a subtree 10 levels deep under its deepest task, and a subtask can still be created below it.
     */
    public function testThereIsNoDepthLimitAsOnCreate(): void
    {
        $deepest = null;
        for ($level = 0; $level < 30; ++$level) {
            $deepest = $this->task($this->roadmap, 'Level ' . $level, parent: $deepest);
        }
        $root = $this->task($this->roadmap, 'Subtree');
        $leaf = $root;
        for ($level = 1; $level < 10; ++$level) {
            $leaf = $this->task($this->roadmap, 'Subtree ' . $level, parent: $leaf);
        }

        $this->send('PUT', '/api/tasks/v2/task/' . $root->getId(), ['parent' => $deepest?->getId()]);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Deeper', 'parent' => $leaf->getId()]);
        self::assertResponseIsSuccessful();

        $depth = 0;
        for ($id = $this->json()['id']; $id !== null; $id = $this->parentOfId($id)) {
            ++$depth;
        }
        self::assertSame(41, $depth);
    }

    public function testTheEditRightOnTheBoardIsNeeded(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story');
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->grant();
            $event->markReadOnly();
        });

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);

        $this->assertRefused(403, 'ACCESS_002');
        $this->assertNothingWritten($task, 'Story');
    }

    public function testAMyTasksTokenMayNotReparentEvenItsOwnTask(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story');
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);

        $this->assertRefused(403, 'TOKEN_001');
        $this->assertNothingWritten($task, 'Story');
    }

    public function testAParentOutOfReachIsNotFoundAndAWrongTypeIsInvalid(): void
    {
        $task  = $this->task($this->roadmap, 'Story');
        $other = $this->task($this->board('Hidden', tenantKey: 'tenant-b'), 'Hidden');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $other->getId()]);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => 'missing']);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => 7]);
        $this->assertRefused(400, 'INPUT_002');
        $this->assertNothingWritten($task, 'Story');
    }

    public function testWithABaseRevisionAndOtherFieldsTheUpdateIsOneTransaction(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId(), 'name' => 'Stale'], ['HTTP_IF_MATCH' => '"7"']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TASK_409', $this->json()['ECODE']);
        $this->assertNothingWritten($task, 'Story');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId(), 'name' => 'Renamed', 'base_revision' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame([$parent->getId(), 'Renamed'], [$this->json()['parent'], $this->json()['name']]);
        $event = end($this->audit()->events);
        self::assertNotFalse($event);
        $changed = $event->changedFields;
        sort($changed);
        self::assertSame(['fields', 'parent'], $changed);
    }

    /** A deadlock or a lock wait timeout against a concurrent tree change: 409 `PARENT_004`, nothing written. */
    public function testALostLockIsAConflictAndWritesNothing(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story');
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(): never
            {
                throw new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException {}, null);
            }
        });

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId(), 'name' => 'Renamed']);

        $this->assertRefused(409, 'PARENT_004');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$task->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_task_change_history WHERE change_type = ?', ['parent']));
    }

    /**
     * MySQL / PostgreSQL only (CI jobs): another process re-parents in the same tree and holds the new parent's row
     * (`SELECT … FOR UPDATE`, as `TaskReparenter` locks it). This request waits for that lock; when the wait times out
     * the answer is 409 `PARENT_004` and nothing is written. Once the other transaction ends, the same request passes.
     */
    public function testARowHeldByAConcurrentTreeChangeIsAConflict(): void
    {
        $connection = $this->em->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Row locks need MySQL / MariaDB or PostgreSQL (SQLite serialises writers).');
        }
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story');
        $other  = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        $other->beginTransaction();
        $other->executeQuery('SELECT id FROM task_board_tasks WHERE id = ? FOR UPDATE', [$parent->getId()]);
        $connection->executeStatement($platform instanceof PostgreSQLPlatform ? "SET lock_timeout = '300ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId(), 'name' => 'Renamed']);
            $this->assertRefused(409, 'PARENT_004');
            $this->assertNothingWritten($task, 'Story');
        } finally {
            $other->rollBack();
            $other->close();
        }

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame($parent->getId(), $this->parentInDatabase($task));
    }

    /** Other failures of the update reach the client as before, never as `PARENT_004`. */
    public function testAnotherFailureIsNotAConflict(): void
    {
        $task = $this->task($this->roadmap, 'Story');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => null, 'status' => 'Missing column']);

        self::assertResponseStatusCodeSame(400);
        self::assertNotSame('PARENT_004', $this->json()['ECODE'] ?? null);
    }

    /** The answer is the task as `GET /v2/task/{id}` gives it (ClickUp's shape), with the new parent. */
    public function testTheAnswerIsTheTaskAsReadContract(): void
    {
        $parent = $this->task($this->roadmap, 'Epic');
        $task   = $this->task($this->roadmap, 'Story');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['parent' => $parent->getId()]);
        self::assertResponseIsSuccessful();
        $written = $this->json();
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame(array_keys($this->json()), array_keys($written), 'the same fields, in the same order');
        self::assertSame([$parent->getId(), 2], [$this->json()['parent'], $this->json()['revision']]);

        // A bulk update does not take `parent`: refused as any field it does not take, nothing written.
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $task->getId(), 'parent' => null, 'description' => 'Bulk']]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('BULK_001', $this->json()['ECODE']);
        self::assertSame($parent->getId(), $this->parentInDatabase($task));
    }

    private function assertNothingWritten(Task $task, string $title): void
    {
        $connection = $this->em->getConnection();
        self::assertSame(
            [null, $title, 1],
            [$this->parentInDatabase($task), $connection->fetchOne('SELECT title FROM task_board_tasks WHERE id = ?', [$task->getId()]), (int) $connection->fetchOne('SELECT revision FROM task_board_tasks WHERE id = ?', [$task->getId()])],
        );
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_task_change_history WHERE change_type = ?', ['parent']));
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    private function parentInDatabase(Task $task): ?string
    {
        return $this->parentOfId($task->getId());
    }

    private function parentOfId(string $id): ?string
    {
        $parent = $this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$id]);

        return $parent === null || $parent === false ? null : (string) $parent;
    }

    /**
     * @return list<TaskChangeHistory>
     */
    private function parentHistory(Task $task): array
    {
        $this->em->clear();

        return $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task->getId(), 'changeType' => TaskChangeType::Parent]);
    }

    private function userIdOf(TaskChangeHistory $entry): mixed
    {
        $user = $entry->getUser();
        self::assertNotNull($user);

        return method_exists($user, 'getId') ? $user->getId() : null;
    }

    private function audit(): ApiAuditRecorder
    {
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);

        return $audit;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
