<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Entity\Task;
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
