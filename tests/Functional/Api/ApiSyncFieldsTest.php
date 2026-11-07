<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
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
}
