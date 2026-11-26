<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\ClientApi\TaskClientCursor;
use Nowo\TaskBoardBundle\ClientApi\TaskClientIdempotency;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_column;
use function hash;
use function str_repeat;

/**
 * Since 3.4.0 (client API v1): the less travelled answers — idempotency outcomes, filters and lookups that refuse, the
 * link kinds of a detail, a context whose `where` names boards, folders as groups.
 */
final class ClientApiEdgeTest extends ClientApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
    }

    public function testIdempotencyOutcomes(): void
    {
        $body = ['boardId' => $this->roadmap->getId(), 'title' => 'Keyed'];
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => str_repeat('k', 256)]);
        $this->assertError(400, 'invalid_field');

        // A claim of the same key still running.
        $this->em->getConnection()->insert('task_board_api_idempotency', ['scope' => TaskClientIdempotency::scope('tok-' . self::CLIENT_TOKEN), 'key_hash' => hash('sha256', 'running'), 'fingerprint' => 'other', 'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'expires_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s')]);
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'running']);
        $this->assertError(409, 'idempotency_mismatch');

        // A replay re-checks reach: once the context no longer reaches the board, 404.
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'replay']);
        self::assertResponseStatusCodeSame(201);
        ClientApiTestHost::$contexts['owner@example.com'] = ['personal' => ['tenant-z']];
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'replay']);
        $this->assertError(404, 'not_found');

        // An optional key on a change is kept too; a refused write releases its key.
        ClientApiTestHost::$contexts['owner@example.com'] = ['personal' => ['tenant-a']];
        $task                                             = $this->task($this->roadmap, 'Patched');
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'Again'], ['HTTP_IF_MATCH' => '"9"', 'HTTP_IDEMPOTENCY_KEY' => 'patch']);
        $this->assertError(409, 'conflict');
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'Again'], ['HTTP_IF_MATCH' => '"1"', 'HTTP_IDEMPOTENCY_KEY' => 'patch']);
        self::assertResponseIsSuccessful();
        $this->call('PATCH', '/tasks/' . $task->getId(), ['title' => 'Again'], ['HTTP_IF_MATCH' => '"1"', 'HTTP_IDEMPOTENCY_KEY' => 'patch']);
        self::assertResponseHeaderSame('Idempotency-Replayed', 'true');
        $this->call('PATCH', '/tasks/' . $task->getId(), 'not json', ['HTTP_IF_MATCH' => '"1"', 'HTTP_IDEMPOTENCY_KEY' => 'raw']);
        $this->assertError(400, 'invalid_json');

        // The store failing is never a silent success.
        $this->em->getConnection()->executeStatement('DROP TABLE task_board_api_idempotency');
        $this->call('POST', '/tasks', $body, ['HTTP_IDEMPOTENCY_KEY' => 'broken']);
        $this->assertError(503, 'idempotency_unavailable');
    }

    public function testLookupsAndFiltersThatRefuse(): void
    {
        $parent = $this->task($this->roadmap, 'Parent');
        $this->task($this->roadmap, 'Child', 0, $parent);
        $other = $this->board('Other', tenantKey: 'tenant-a');
        $alien = $this->task($other, 'Alien');
        $path  = '/boards/' . $this->roadmap->getId() . '/tasks';

        $this->call('GET', $path . '?parent=' . $parent->getId());
        self::assertSame(['Child'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?parent=' . $alien->getId());
        $this->assertError(400, 'invalid_field');
        $this->call('GET', $path . '?due[]=x');
        $this->assertError(400, 'invalid_field');
        $tags = '';
        for ($i = 0; $i < 21; ++$i) {
            $tags .= '&tag=t' . $i;
        }
        $this->call('GET', $path . '?' . $tags);
        $this->assertError(400, 'invalid_field');

        $this->call('GET', '/tasks/no%20such');
        $this->assertError(404, 'not_found');
        $this->call('GET', '/tasks/%2312');
        $this->assertError(404, 'not_found');
        $this->call('GET', '/tasks/12?board=' . $this->roadmap->getId());
        $this->assertError(404, 'not_found');

        $this->call('GET', '/changes?since=' . TaskClientCursor::encode('changes/personal', ['not a date', 'x']));
        $this->assertError(400, 'invalid_cursor');
        $this->call('GET', '/changes?since=' . TaskClientCursor::encode('changes/personal', ['2020-01-01 00:00:00', '']));
        self::assertTrue($this->json()['reset'], 'a cursor older than a day');

        // An archived board is not found.
        $fresh = $this->em->find(TaskBoard::class, $other->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $fresh->archive();
        $this->em->flush();
        $this->call('GET', '/boards/' . $other->getId());
        $this->assertError(404, 'not_found');

        // A person the host no longer knows and a team the host refuses.
        ApiTestHost::$members['team-1'] = [new TaskApiMember('uuid-' . $this->owner->getId(), 'Owner', false)];
        $this->call('GET', '/people?board=' . $this->roadmap->getId() . '&q=own');
        self::assertCount(1, $this->json()['people']);
        ApiTestHost::$tenants = ['tenant-a'];
        $this->call('GET', '/people?board=' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
    }

    public function testAContextOfNamedBoardsAndAContextOfEverything(): void
    {
        $folder = new TaskBoardFolder('Planning', 'tenant-b');
        $this->em->persist($folder);
        $named = $this->board('Named', tenantKey: 'tenant-b');
        $fresh = $this->em->find(TaskBoard::class, $named->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $fresh->changeFolder($folder);
        $this->em->flush();
        ClientApiTestHost::$wheres['owner@example.com']['org:named']   = TaskApiWhere::limitedTo([], [$named->getId(), $named->getId(), 'gone']);
        ClientApiTestHost::$contexts['owner@example.com']['org:named'] = [];
        $this->call('GET', '/boards', context: 'org:named');
        $this->call('GET', '/boards', context: 'org:named');
        self::assertSame([['type' => 'folder', 'label' => 'Planning']], array_map(static fn (array $board): array => ['type' => $board['group']['type'], 'label' => $board['group']['label']], $this->json()['boards']));

        ClientApiTestHost::$wheres['owner@example.com']['org:all']   = TaskApiWhere::everything();
        ClientApiTestHost::$contexts['owner@example.com']['org:all'] = [];
        $this->call('GET', '/boards', context: 'org:all');
        self::assertSame([], $this->json()['boards'], 'everything is never a context: nothing is listed');
        $this->call('GET', '/my-tasks', context: 'org:all');
        self::assertSame([], $this->json()['tasks']);
        $this->call('GET', '/changes?since=' . TaskClientCursor::encode('changes/org:all', [(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), '']), context: 'org:all');
        self::assertSame([], $this->json()['changes']);
    }

    public function testEveryKindOfLinkInADetail(): void
    {
        $task  = $this->task($this->roadmap, 'Linked');
        $child = $this->task($this->roadmap, 'Archived child', 0, $task);
        $fresh = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);
        $this->em->persist(new TaskLink($fresh, TaskLinkType::Url, 'https://example.com/doc', 'Spec'));
        $this->em->persist(new TaskLink($fresh, TaskLinkType::Attachment, '/files/1', 'File'));
        $archived = $this->em->find(Task::class, $child->getId());
        self::assertInstanceOf(Task::class, $archived);
        $archived->archive();
        $this->em->flush();

        $this->call('GET', '/tasks/' . $task->getId());
        self::assertEqualsCanonicalizing([['type' => 'url', 'url' => 'https://example.com/doc', 'title' => 'Spec', 'visible' => true], ['type' => 'attachment', 'visible' => false]], $this->json()['task']['links']);
        self::assertSame(['total' => 0, 'done' => 0], $this->json()['task']['subtaskSummary']);
    }

    public function testWritesThatRefuse(): void
    {
        $parent = $this->task($this->roadmap, 'Parent');
        $other  = $this->task($this->board('Other', tenantKey: 'tenant-a'), 'Elsewhere');
        $this->call('POST', '/tasks', ['boardId' => $this->roadmap->getId(), 'title' => 'Sub', 'parentId' => $parent->getId()], ['HTTP_IDEMPOTENCY_KEY' => 'sub']);
        self::assertResponseStatusCodeSame(201);
        $this->call('POST', '/tasks', ['boardId' => $this->roadmap->getId(), 'title' => 'Sub', 'parentId' => $other->getId()], ['HTTP_IDEMPOTENCY_KEY' => 'sub-2']);
        $this->assertError(400, 'invalid_field');
        $this->call('POST', '/tasks', ['boardId' => $this->roadmap->getId(), 'title' => 'Long', 'description' => str_repeat('d', 100_001)], ['HTTP_IDEMPOTENCY_KEY' => 'long']);
        $this->assertError(400, 'invalid_field');

        // A subtask moves among its siblings; a card counts its comments.
        $sub  = $this->task($this->roadmap, 'Second sub', 0, $parent);
        $todo = $this->roadmap->getColumns()->get(0);
        self::assertNotNull($todo);
        $this->call('POST', '/tasks/' . $sub->getId() . '/move', ['columnId' => $todo->getId()], ['HTTP_IF_MATCH' => '*']);
        self::assertResponseIsSuccessful();
        $this->em->persist(new TaskComment($this->em->find(Task::class, $parent->getId()) ?? $parent, $this->em->find(AppUser::class, $this->owner->getId()), 'Hi'));
        $this->em->flush();
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?q=Parent');
        self::assertSame(1, $this->json()['tasks'][0]['comments']);

        // The author of a comment the host no longer shows has none.
        $gone = $this->user('ghost@example.com');
        $this->em->persist(new TaskComment($this->em->find(Task::class, $parent->getId()) ?? $parent, $this->em->find(AppUser::class, $gone->getId()), 'Boo'));
        $this->em->flush();
        ApiTestHost::$unknownEmails = ['ghost@example.com'];
        $this->call('GET', '/tasks/' . $parent->getId() . '/comments');
        $authors = [];
        foreach ($this->json()['comments'] as $comment) {
            $authors[$comment['body']['blocks'][0]['tokens'][0]['text']] = $comment['author'];
        }
        self::assertNull($authors['Boo']);
        self::assertNotNull($authors['Hi']);
        ApiTestHost::$unknownEmails = [];

        // allDay alone keeps only the date part of the dates the task has.
        $task = $this->task($this->roadmap, 'Dated');
        $this->call('PATCH', '/tasks/' . $task->getId(), ['dueAt' => '2026-12-01T15:00:00Z'], ['HTTP_IF_MATCH' => '"1"']);
        $this->call('PATCH', '/tasks/' . $task->getId(), ['allDay' => true], ['HTTP_IF_MATCH' => '"' . $this->json()['task']['revision'] . '"']);
        self::assertSame(['2026-12-01T00:00:00Z', true], [$this->json()['task']['dueAt'], $this->json()['task']['allDay']]);

        // A person the host no longer knows exactly is not assignable.
        $colleague                  = $this->user('gone@example.com');
        ApiTestHost::$unknownEmails = ['gone@example.com'];
        $this->call('PUT', '/tasks/' . $task->getId() . '/members/uuid-' . $colleague->getId(), ['role' => 'assignee'], ['HTTP_IF_MATCH' => '"1"']);
        $this->assertError(400, 'invalid_field');
    }
}
