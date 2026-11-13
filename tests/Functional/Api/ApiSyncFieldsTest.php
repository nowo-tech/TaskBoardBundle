<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Spec 213 (Q-O3): a board's custom fields are those a provider sync imported, read-only; an erased value is not shown.
 */
final class ApiSyncFieldsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$hostConfig = SyncFunctionalTestCase::SYNC_CONFIG;
        parent::setUp();
    }

    public function testImportedFieldsAndValues(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $task                 = $this->task($board, 'Synced');
        $now                  = new DateTimeImmutable('@1790000000');
        $field                = new TaskSyncField($board, 'clickup', 'cf-1', $now);
        $field->define('Story points', SyncFieldType::Number, 'number', [], ['precision' => 0], 0, $now);
        $choice = new TaskSyncField($board, 'clickup', 'cf-2', $now);
        $choice->define('Stage', SyncFieldType::Text, 'drop_down', [['id' => 'o1', 'name' => 'Alpha', 'color' => null]], [], 1, $now);
        $erased = new TaskSyncFieldValue($choice, $task, 'o1', 'Alpha', $now);
        $erased->erase('record', $now);
        foreach ([$field, $choice, new TaskSyncFieldValue($field, $task, 8, '8', $now), $erased] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->issueToken();

        $this->get('/api/tasks/v2/list/' . $board->getId() . '/field');
        self::assertSame([
            ['id' => $field->getId(), 'name' => 'Story points', 'type' => 'number', 'type_config' => ['precision' => 0], 'date_created' => '1790000000000', 'hide_from_guests' => false, 'required' => false],
            ['id' => $choice->getId(), 'name' => 'Stage', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'o1', 'name' => 'Alpha', 'color' => null]]], 'date_created' => '1790000000000', 'hide_from_guests' => false, 'required' => false],
        ], $this->listOf('fields'));

        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame([['id' => $field->getId(), 'name' => 'Story points', 'type' => 'number', 'value' => 8]], $this->json()['custom_fields']);
    }

    public function testAMovedTasksImportedValuesAreHiddenOnTheTargetBoard(): void
    {
        // Spec 217, owner decision 4: a value of another board's field (a task moved off its board) is not returned.
        ApiTestHost::$tenants = ['tenant-a'];
        $source               = $this->board(tenantKey: 'tenant-a');
        $target               = $this->board(tenantKey: 'tenant-a');
        $task                 = $this->task($target, 'Moved here');
        $now                  = new DateTimeImmutable('@1790000000');
        $field                = new TaskSyncField($source, 'clickup', 'cf-1', $now);
        $field->define('Story points', SyncFieldType::Number, 'number', [], [], 0, $now);
        $this->em->persist($field);
        $this->em->persist(new TaskSyncFieldValue($field, $task, 8, '8', $now));
        $this->em->flush();
        $this->issueToken();

        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame([], $this->json()['custom_fields']);
        self::assertCount(1, $this->em->getRepository(TaskSyncFieldValue::class)->findAll(), 'kept');
    }

    /** Since 2.17.0 (FR-033): a done column retyped as custom reopens its tasks, so the limit is asked first. */
    public function testRetypingADoneColumnAsksTheLimit(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $doneColumn           = $board->getColumns()->last() ?: null;
        self::assertNotNull($doneColumn);
        $this->em->persist((new Task(board: $board, title: 'Done', creator: $this->owner, column: $doneColumn))->markCompleted());
        $this->em->flush();
        $this->issueToken();
        TestSyncLimitChecker::$refuseTasks = true;
        try {
            $this->send('PUT', '/api/tasks/v2/list/' . $board->getId() . '/status/' . $doneColumn->getId(), ['type' => 'custom']);
            $this->assertRefused(403, 'PLAN_002');
        } finally {
            TestSyncLimitChecker::reset();
        }
    }

    public function testTheHostsLimitOfActiveTasksRefusesAReopening(): void
    {
        // The limit TaskManager asks when a completed task leaves the done column (sync.limit_checker), as in the UI.
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $done                 = new Task(board: $board, title: 'Done', creator: $this->owner, column: $board->getColumns()->last() ?: null);
        $item                 = new Task(board: $board, title: 'Item', creator: $this->owner, column: $board->getColumns()->last() ?: null, parent: $done, tags: ['checklist:Steps']);
        $this->em->persist($done->markCompleted());
        $this->em->persist($item->markCompleted());
        $this->em->flush();
        $this->issueToken();
        TestSyncLimitChecker::$refuseTasks = true;

        try {
            $this->send('PUT', '/api/tasks/v2/task/' . $done->getId(), ['status' => 'To do']);
            $this->assertRefused(403, 'PLAN_002');
            $this->send('PUT', '/api/tasks/v2/checklist/' . TaskApiReadSerializer::checklistId($done->getId(), 'Steps') . '/checklist_item/' . $item->getId(), ['resolved' => false]);
            $this->assertRefused(403, 'PLAN_002');
        } finally {
            TestSyncLimitChecker::reset();
        }
        $this->em->clear();
        self::assertTrue($this->em->find(Task::class, $done->getId())?->isCompleted());
        self::assertTrue($this->em->find(Task::class, $item->getId())?->isCompleted());
    }

    /** Audit IB-L1: a write of fields never moves a task, even a done one without a column, so it asks no limit. */
    public function testFieldWritesNeverMoveATaskWithoutAColumn(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $done                 = new Task(board: $board, title: 'Loose', creator: $this->owner);
        $item                 = new Task(board: $board, title: 'Item', creator: $this->owner, parent: $done, tags: ['checklist:Steps']);
        $this->em->persist($done->markCompleted());
        $this->em->persist($item->markCompleted());
        $this->em->flush();
        $this->issueToken();
        TestSyncLimitChecker::$refuseTasks = true;
        $checklist                         = '/api/tasks/v2/checklist/' . TaskApiReadSerializer::checklistId($done->getId(), 'Steps');

        try {
            $this->send('PUT', '/api/tasks/v2/task/' . $done->getId(), ['name' => 'Renamed']);
            self::assertResponseIsSuccessful();
            $this->send('POST', '/api/tasks/v2/task/' . $done->getId() . '/tag/Urgent');
            self::assertResponseIsSuccessful();
            $this->send('PUT', $checklist . '/checklist_item/' . $item->getId(), ['name' => 'Item renamed']);
            self::assertResponseIsSuccessful();
            $this->send('PUT', $checklist, ['name' => 'Stages']);
            self::assertResponseIsSuccessful();
            self::assertSame([], TestSyncLimitChecker::$calls, 'nothing became active');
        } finally {
            TestSyncLimitChecker::reset();
        }
        $this->em->clear();
        foreach ([[$done, 'Renamed', ['urgent']], [$item, 'Item renamed', ['checklist:stages']]] as [$task, $title, $tags]) {
            $kept = $this->em->find(Task::class, $task->getId());
            self::assertInstanceOf(Task::class, $kept);
            self::assertSame([$title, null, true], [$kept->getTitle(), $kept->getColumn(), $kept->isCompleted()]);
            self::assertSame($tags, array_map('mb_strtolower', $kept->getTags()));
        }
    }

    /** Audit IB-L1: the limit refusing a move after the first check (it changed in between) is 403 PLAN_002, not a 500. */
    public function testALimitThatChangesAfterTheCheckIsStillPlan002(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $done                 = new Task(board: $board, title: 'Done', creator: $this->owner, column: $board->getColumns()->last() ?: null);
        $this->em->persist($done->markCompleted());
        $this->em->flush();
        $this->issueToken();
        TestSyncLimitChecker::$refuseTasks      = true;
        TestSyncLimitChecker::$allowedTaskCalls = 1;

        try {
            $this->send('PUT', '/api/tasks/v2/task/' . $done->getId(), ['status' => 'To do']);
            $this->assertRefused(403, 'PLAN_002');
            self::assertSame([[1, 0], [1, 0]], TestSyncLimitChecker::$calls, 'asked once before any save, then again by the move');
        } finally {
            TestSyncLimitChecker::reset();
        }
        $this->em->clear();
        self::assertTrue($this->em->find(Task::class, $done->getId())?->isCompleted());
    }

    public function testEveryLimitIsAskedBeforeTheFirstSave(): void
    {
        // Audit IA-L6: restoring an archived done task and moving it out of the done column; the limit refuses, nothing is saved.
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $task                 = new Task(board: $board, title: 'Shelved', creator: $this->owner, column: $board->getColumns()->last() ?: null);
        $open                 = new Task(board: $board, title: 'Shelved open', creator: $this->owner);
        $this->em->persist($task->markCompleted()->archive());
        $this->em->persist($open->archive());
        $this->em->flush();
        $this->issueToken();
        TestSyncLimitChecker::$refuseTasks = true;

        try {
            $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['archived' => false, 'status' => 'To do']);
            $this->assertRefused(403, 'PLAN_002');
            $this->send('PUT', '/api/tasks/v2/task/' . $open->getId(), ['archived' => false, 'name' => 'Renamed']);
            $this->assertRefused(403, 'PLAN_002');
        } finally {
            TestSyncLimitChecker::reset();
        }
        $this->em->clear();
        self::assertTrue($this->em->find(Task::class, $task->getId())?->isArchived(), 'still archived');
        self::assertTrue($this->em->find(Task::class, $task->getId())?->isCompleted());
        self::assertSame('Shelved open', $this->em->find(Task::class, $open->getId())?->getTitle());
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);
        self::assertSame([], $audit->events, 'nothing written, nothing audited');
    }

    public function testDeletingTheDoneColumnAsksTheLimitForTheTasksItReopens(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $done                 = $board->getColumns()->last();
        self::assertInstanceOf(BoardColumn::class, $done);
        $this->em->persist((new Task(board: $board, title: 'Finished', creator: $this->owner, column: $done))->markCompleted());
        $this->em->flush();
        $this->issueToken();
        $this->em->clear();
        TestSyncLimitChecker::$refuseTasks = true;

        try {
            $this->send('DELETE', '/api/tasks/v2/list/' . $board->getId() . '/status/' . $done->getId());
        } finally {
            TestSyncLimitChecker::reset();
        }

        $this->assertRefused(403, 'PLAN_002');
        $this->em->clear();
        self::assertCount(3, $this->em->find(TaskBoard::class, $board->getId())?->getColumns() ?? []);
    }

    public function testABoardThatFollowsAProviderIsNotDeletedThroughTheApi(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $this->em->persist(new TaskBoardConnection($board, 'clickup', 'list', 'conn-1', 'L1', 'Backlog'));
        $this->em->flush();
        $this->issueToken();

        $this->send('DELETE', '/api/tasks/v2/list/' . $board->getId());

        $this->assertRefused(409, 'LIST_002');
        $this->em->clear();
        self::assertNotNull($this->em->find(TaskBoard::class, $board->getId()));
    }

    public function testATaskThatFollowsAProviderIsNotDeletedThroughTheApi(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board(tenantKey: 'tenant-a');
        $task                 = $this->task($board, 'Synced');
        $connection           = new TaskBoardConnection($board, 'clickup', 'list', 'conn-1', 'L1', 'Backlog');
        $this->em->persist($connection);
        $this->em->persist(new TaskSyncItem($connection, $board->getId(), $task, 'remote-1', new DateTimeImmutable('@1790000000')));
        $this->em->flush();
        $this->issueToken();

        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId());

        $this->assertRefused(409, 'TASK_002');
        self::assertCount(1, $this->em->getRepository(Task::class)->findAll());
    }
}
