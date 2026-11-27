<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSourceLink;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\RecordingTransitionPolicy;

use function array_column;
use function array_map;

/**
 * Since 3.4.0 (client API v1, §4.3, §8): the core writes — through the UI's services, with channel `app`, revisions
 * (`If-Match`, 428 / 409), `Idempotency-Key` (required on creates) and the audit.
 */
final class ClientApiWriteTest extends ClientApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        self::$hostConfig = ['column_transition_policy' => 'app.transition_policy'];
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
    }

    public function testQuickCreateNeedsAKeyAndAKeyRunsOnce(): void
    {
        $body = ['boardId' => $this->roadmap->getId(), 'title' => 'From the desk', 'priority' => 'high', 'tags' => ['desk'], 'assignees' => ['me'], 'dueAt' => '2026-12-01T15:30:00+01:00', 'allDay' => true, 'estimateMinutes' => 90, 'description' => 'Hello'];
        $this->call('POST', '/tasks', $body);
        $this->assertError(400, 'invalid_field');
        self::assertSame('Idempotency-Key', $this->json()['field']);

        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'create-1']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $task = $this->json()['task'];
        self::assertSame(['From the desk', 'high', ['desk'], '2026-12-01T00:00:00Z', true, 90, 'To do'], [$task['title'], $task['priority'], $task['tags'], $task['dueAt'], $task['allDay'], $task['estimateMinutes'], $task['column']['name']]);
        self::assertSame(['uuid-' . $this->owner->getId()], array_column($task['assignees'], 'id'));
        self::assertSame('app', $task['channel'] ?? 'app');
        self::assertResponseHeaderSame('ETag', '"' . $task['revision'] . '"');

        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'create-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        self::assertSame($task['id'], $this->json()['task']['id']);
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['title' => 'From the desk']));

        $this->call('POST', '/tasks', ['title' => 'Other'] + $body, ['HTTP_IDEMPOTENCY_KEY' => 'create-1']);
        $this->assertError(409, 'idempotency_mismatch');

        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);
        self::assertSame('app', $audit->events[0]->channel);
        self::assertSame('task.create', $audit->events[0]->operation);
    }

    public function testACreateIsCheckedLikeTheForms(): void
    {
        $board = $this->roadmap->getId();
        foreach ([
            [['boardId' => $board, 'title' => ''], 'title'],
            [['boardId' => $board, 'title' => 'x', 'priority' => 'extreme'], 'priority'],
            [['boardId' => $board, 'title' => 'x', 'dueAt' => '2026-12-01'], 'dueAt'],
            [['boardId' => $board, 'title' => 'x', 'startAt' => '2026-12-02T00:00:00Z', 'dueAt' => '2026-12-01T00:00:00Z'], 'startAt'],
            [['boardId' => $board, 'title' => 'x', 'columnId' => 'nope'], 'columnId'],
            [['boardId' => $board, 'title' => 'x', 'assignees' => ['uuid-999']], 'assignees'],
            [['boardId' => $board, 'title' => 'x', 'templateId' => 'tpl'], 'templateId'],
            [['boardId' => $board, 'title' => 'x', 'unknown' => true], 'unknown'],
            [['boardId' => $board, 'title' => 'x', 'estimateMinutes' => -1], 'estimateMinutes'],
            [['boardId' => $board, 'title' => 'x', 'source' => ['type' => 'BAD']], 'source'],
        ] as $index => [$body, $field]) {
            $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'bad-' . $index]);
            $this->assertError(400, 'invalid_field');
            self::assertSame($field, $this->json()['field'], $field);
        }
        $this->call('POST', '/tasks', '{not json', ['HTTP_IDEMPOTENCY_KEY' => 'bad-json']);
        $this->assertError(400, 'invalid_json');
        $this->call('POST', '/tasks', '[1]', ['HTTP_IDEMPOTENCY_KEY' => 'bad-list']);
        $this->assertError(400, 'invalid_json');
        $this->call('POST', '/tasks', ['boardId' => 'unknown', 'title' => 'x'], ['HTTP_IDEMPOTENCY_KEY' => 'bad-board']);
        $this->assertError(404, 'not_found');
    }

    public function testASourceIsLinkedOnlyWhenTheHostSaysThePersonMaySeeIt(): void
    {
        ClientApiTestHost::$sources['chat_message:m-1'] = new TaskClientSourceLink('chat_message', 'm-1', 'Message');
        $this->call('POST', '/tasks', ['boardId' => $this->roadmap->getId(), 'title' => 'From chat', 'source' => ['type' => 'chat_message', 'conversationUuid' => 'c-1', 'messageUuid' => 'm-1']], ['HTTP_IDEMPOTENCY_KEY' => 'src-1']);
        self::assertResponseStatusCodeSame(201);
        $links = $this->em->getRepository(TaskLink::class)->findAll();
        self::assertSame([['chat_message', 'm-1']], array_map(static fn (TaskLink $link): array => [$link->getObjectType(), $link->getObjectId()], $links));

        $this->call('POST', '/tasks', ['boardId' => $this->roadmap->getId(), 'title' => 'Hidden', 'source' => ['type' => 'chat_message', 'messageUuid' => 'm-2']], ['HTTP_IDEMPOTENCY_KEY' => 'src-2']);
        $this->assertError(422, 'source_not_visible');
    }

    public function testAnUpdateNeedsTheRevisionAndRefusesAStaleOne(): void
    {
        $task = $this->task($this->roadmap, 'Edit me');
        $path = '/tasks/' . $task->getId();
        $this->call('PATCH', $path, ['title' => 'Edited']);
        $this->assertError(428, 'precondition_required');

        $this->call('PATCH', $path, ['title' => 'Edited', 'color' => '#ff0000', 'tags' => ['a', 'b'], 'description' => 'Text', 'startAt' => null], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['Edited', '#ff0000', ['a', 'b'], 2], [$this->json()['task']['title'], $this->json()['task']['color'], $this->json()['task']['tags'], $this->json()['task']['revision']]);
        self::assertResponseHeaderSame('ETag', '"2"');

        $this->call('PATCH', $path, ['title' => 'Lost'], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(409, 'conflict');
        self::assertSame(2, $this->json()['currentRevision']);

        $this->call('PATCH', $path, ['archived' => true], ['HTTP_IF_MATCH' => '2']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['task']['archived']);
        $revision = $this->json()['task']['revision'];
        $this->call('PATCH', $path, ['archived' => false], ['HTTP_IF_MATCH' => '"' . $revision . '"']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['task']['archived']);

        foreach ([['status' => 'x'], ['title' => 5], ['color' => 'red'], ['archived' => 'yes'], ['tags' => 'a'], ['tags' => [1]]] as $body) {
            $this->call('PATCH', $path, $body, ['HTTP_IF_MATCH' => '"99"']);
            $this->assertError(400, 'invalid_field');
        }
        foreach (['*', 'abc', '"0"'] as $ifMatch) {
            $this->call('PATCH', $path, ['title' => 'x'], ['HTTP_IF_MATCH' => $ifMatch]);
            self::assertContains($this->client->getResponse()->getStatusCode(), [400, 428]);
        }
    }

    public function testAMoveBetweenColumnsNeedsTheRevisionAndAReorderMayUseAny(): void
    {
        $first  = $this->task($this->roadmap, 'First', 0, null, 0);
        $second = $this->task($this->roadmap, 'Second', 0, null, 1);
        $doing  = $this->roadmap->getColumns()->get(1);
        $todo   = $this->roadmap->getColumns()->get(0);
        self::assertNotNull($doing);
        self::assertNotNull($todo);

        $this->call('POST', '/tasks/' . $first->getId() . '/move', ['columnId' => $doing->getId()]);
        $this->assertError(428, 'precondition_required');
        $this->call('POST', '/tasks/' . $first->getId() . '/move', ['columnId' => $doing->getId()], ['HTTP_IF_MATCH' => '*']);
        $this->assertError(428, 'precondition_required');

        $this->call('POST', '/tasks/' . $first->getId() . '/move', ['columnId' => $doing->getId()], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('Doing', $this->json()['task']['column']['name']);
        self::assertSame(['app', 'move'], [RecordingTransitionPolicy::$asked[0]->via->channel ?? null, RecordingTransitionPolicy::$asked[0]->via->operation ?? null]);

        // Back before the second task, inside its column: any revision.
        $this->call('POST', '/tasks/' . $first->getId() . '/move', ['columnId' => $todo->getId(), 'beforeTaskId' => $second->getId()], ['HTTP_IF_MATCH' => '"' . $this->json()['task']['revision'] . '"']);
        self::assertResponseIsSuccessful();
        $this->call('POST', '/tasks/' . $second->getId() . '/move', ['columnId' => $todo->getId(), 'afterTaskId' => $first->getId()], ['HTTP_IF_MATCH' => '*']);
        self::assertResponseIsSuccessful();
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $todo->getId());
        self::assertSame(['First', 'Second'], array_column($this->json()['tasks'], 'title'));

        $this->call('POST', '/tasks/' . $second->getId() . '/move', ['columnId' => $todo->getId(), 'afterTaskId' => 'unknown'], ['HTTP_IF_MATCH' => '*']);
        $this->assertError(400, 'invalid_field');

        RecordingTransitionPolicy::$wipFull = ['Doing'];
        $this->call('POST', '/tasks/' . $second->getId() . '/move', ['columnId' => $doing->getId()], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(403, 'transition_refused');
        self::assertSame('WIP_LIMIT', $this->json()['policyCode']);

        // The app is a person (Q-BD-24): a humans-only column lets it in.
        RecordingTransitionPolicy::$wipFull = [];
        $fresh                              = $this->em->find(BoardColumn::class, $doing->getId());
        self::assertNotNull($fresh);
        $fresh->setHumansOnly(true);
        $this->em->flush();
        $this->call('POST', '/tasks/' . $second->getId() . '/move', ['columnId' => $doing->getId()], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    public function testMembersAreAddedAndRemovedWithTheRevision(): void
    {
        $task      = $this->task($this->roadmap, 'Team work');
        $colleague = $this->user('colleague@example.com');
        $path      = '/tasks/' . $task->getId() . '/members/uuid-' . $colleague->getId();

        $this->call('PUT', $path, ['role' => 'reviewer']);
        $this->assertError(428, 'precondition_required');
        $this->call('PUT', $path, ['role' => 'reviewer'], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame([['user' => 'uuid-' . $colleague->getId(), 'role' => 'reviewer']], array_map(static fn (array $member): array => ['user' => $member['user']['id'], 'role' => $member['role']], $this->json()['task']['members']));

        $this->call('DELETE', $path, null, ['HTTP_IF_MATCH' => '"' . $this->json()['task']['revision'] . '"']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['task']['members']);

        $this->call('PUT', '/tasks/' . $task->getId() . '/members/uuid-999', ['role' => 'assignee'], ['HTTP_IF_MATCH' => '"' . $this->json()['task']['revision'] . '"']);
        $this->assertError(400, 'invalid_field');
        $this->call('PUT', $path, ['role' => 'boss'], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(400, 'invalid_field');
    }

    public function testChecklistItemsAreCreatedOnceAndTickedWithTheirOwnRevision(): void
    {
        $task = $this->task($this->roadmap, 'Launch');
        $path = '/tasks/' . $task->getId() . '/checklist-items';
        $this->call('POST', $path, ['checklist' => 'Go live', 'title' => 'DNS']);
        $this->assertError(400, 'invalid_field');
        $this->call('POST', $path, ['checklist' => 'Go live', 'title' => 'DNS'], ['HTTP_IDEMPOTENCY_KEY' => 'item-1']);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $itemId = $this->json()['itemId'];
        self::assertSame([['name' => 'Go live', 'items' => [['id' => $itemId, 'title' => 'DNS', 'done' => false, 'revision' => 1]]]], $this->json()['task']['checklists']);

        $this->call('PATCH', $path . '/' . $itemId, ['done' => true, 'title' => 'DNS records'], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertTrue($this->json()['task']['checklists'][0]['items'][0]['done']);
        self::assertSame('DNS records', $this->json()['task']['checklists'][0]['items'][0]['title']);

        $this->call('PATCH', $path . '/' . $itemId, ['done' => false], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(409, 'conflict');
        $this->call('PATCH', $path . '/' . $itemId, ['done' => false], ['HTTP_IF_MATCH' => '"' . $this->json()['currentRevision'] . '"']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['task']['checklists'][0]['items'][0]['done']);

        $this->call('PATCH', $path . '/unknown-item', ['done' => true], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(404, 'not_found');
        $this->call('POST', $path, ['checklist' => '', 'title' => 'x'], ['HTTP_IDEMPOTENCY_KEY' => 'item-2']);
        $this->assertError(400, 'invalid_field');
    }

    public function testARoleChangeOfMembersNeedsAnEditRight(): void
    {
        $task = $this->task($this->roadmap, 'Members');
        $this->call('PUT', '/tasks/' . $task->getId() . '/members/me', ['role' => TaskMemberRole::Watcher->value], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful();
        self::assertSame('watcher', $this->json()['task']['members'][0]['role']);
    }
}
