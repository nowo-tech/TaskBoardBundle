<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskStatusChangedEvent;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\RecordingTransitionPolicy;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function range;
use function strtoupper;

/**
 * Spec 234 (TaskBoard 2.16.0): the column transition policy on every channel, `humans_only`, the task revision with
 * `If-Match` / `base_revision`, the atomic bulk update, TASK_STATUS_CHANGED and `PUT /v2/comment/{id}`.
 */
final class ApiTransitionAndRevisionTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    /** @var list<TaskStatusChangedEvent> */
    private array $statusEvents = [];

    protected function setUp(): void
    {
        self::$hostConfig = ['column_transition_policy' => 'app.transition_policy'];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->roadmap        = $this->board('Roadmap', ['To do', 'Doing', 'Approved', 'Done'], tenantKey: 'tenant-a');
        $this->column('Approved')->setHumansOnly(true);
        $this->column('Done')->setHumansOnly(true);
        $this->em->flush();
        $this->issueToken();
        ApiTestHost::$tokens['bt_mcp'] = ['channel' => TaskApiPrincipal::CHANNEL_MCP] + ApiTestHost::$tokens[self::TOKEN];
        $dispatcher                    = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_STATUS_CHANGED, function (TaskStatusChangedEvent $event): void {
            $this->statusEvents[] = $event;
        });
    }

    public function testStatusesShowTheHumansOnlyFlagAndTheApiSetsIt(): void
    {
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId());
        self::assertSame([false, false, true, true], array_map(static fn (array $status): bool => $status['humans_only'], $this->json()['statuses']));

        // Audit PC-M1: an `api` / `mcp` caller may not change the flag; a host's own channel may.
        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status/' . $this->column('Doing')->getId(), ['humans_only' => true]);
        $this->assertRefused(403, 'STATUS_HUMANS_ONLY');
        ApiTestHost::$tokens['bt_cli'] = ['channel' => 'cli'] + ApiTestHost::$tokens[self::TOKEN];
        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status/' . $this->column('Doing')->getId(), ['humans_only' => true], [], 'bt_cli');
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertTrue($this->em->find(BoardColumn::class, $this->column('Doing')->getId())?->isHumansOnly());

        $this->send('PUT', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/status/' . $this->column('Doing')->getId(), ['humans_only' => 'yes']);
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testApiAndMcpMovesIntoAHumansOnlyColumnAre403AndChangeNothing(): void
    {
        $task = $this->task($this->roadmap, 'Verify', 1);
        foreach ([self::TOKEN, 'bt_mcp'] as $token) {
            $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'done'], [], $token);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('STATUS_HUMANS_ONLY', $this->json()['ECODE']);
            self::assertSame('Only people can move tasks into this status', $this->json()['err']);
        }
        self::assertSame(['api', 'mcp'], array_map(static fn (ColumnTransition $transition): string => $transition->via->channel, RecordingTransitionPolicy::$asked));
        self::assertSame('Done', RecordingTransitionPolicy::$asked[0]->to->name);
        self::assertTrue(RecordingTransitionPolicy::$asked[0]->to->last);
        self::assertSame('Doing', RecordingTransitionPolicy::$asked[0]->from?->name);
        self::assertSame($task->getId(), RecordingTransitionPolicy::$asked[0]->task?->id);
        $this->em->clear();
        self::assertSame('Doing', $this->em->find(Task::class, $task->getId())?->getColumn()?->getName());
        self::assertSame([], $this->statusEvents);
    }

    public function testAMoveOutOfAHumansOnlyColumnIsAllowedAndDispatchesStatusChanged(): void
    {
        $task = $this->task($this->roadmap, 'Approved one', 2);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'doing'], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->statusEvents);
        $event = $this->statusEvents[0];
        self::assertSame(['Approved', 'Doing', 'mcp', null], [$event->from?->name, $event->to->name, $event->via->channel, $event->toType]);
        self::assertSame($task->getId(), $event->task->id);
    }

    public function testCreatingInAHumansOnlyColumnIsRefusedWithTheTagsAsked(): void
    {
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Shortcut', 'status' => 'Approved', 'tags' => ['repo:core']], [], 'bt_mcp');
        $this->assertRefused(403, 'STATUS_HUMANS_ONLY');
        self::assertTrue(RecordingTransitionPolicy::$asked[0]->isCreation());
        self::assertSame(['repo:core'], RecordingTransitionPolicy::$asked[0]->tags);
        self::assertSame('create', RecordingTransitionPolicy::$asked[0]->via->operation);
        self::assertCount(0, $this->em->getRepository(Task::class)->findBy(['title' => 'Shortcut']));
    }

    public function testAHostRuleRefusesWithItsOwnCodeAndMessage(): void
    {
        RecordingTransitionPolicy::$wipFull = ['Doing'];
        $task                               = $this->task($this->roadmap, 'Queued');
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'doing']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['err' => 'Only one task per repository in Doing', 'ECODE' => 'WIP_LIMIT'], $this->json());
    }

    public function testTheRevisionIsReturnedWithAnEtagAndBumpedOnEveryChange(): void
    {
        $task = $this->task($this->roadmap, 'Revised');
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame(1, $this->json()['revision']);
        self::assertResponseHeaderSame('ETag', '"1"');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Revised twice'], ['HTTP_IF_MATCH' => '"1"']);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['revision']);
        self::assertResponseHeaderSame('ETag', '"2"');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Lost', 'base_revision' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['err' => 'The task changed: read it again', 'ECODE' => 'TASK_409', 'current_revision' => 2], $this->json());
        $this->em->clear();
        self::assertSame('Revised twice', $this->em->find(Task::class, $task->getId())?->getTitle());

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Kept', 'base_revision' => 2]);
        self::assertSame(3, $this->json()['revision']);
        // A move by position only is no new revision (a drag of another card renumbers its siblings).
        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $reloaded);
        $reloaded->setPosition(9);
        $this->em->flush();
        self::assertSame(3, $reloaded->getRevision());
    }

    public function testAMalformedIfMatchIs400AndAStarMeansAnyRevision(): void
    {
        $task = $this->task($this->roadmap, 'Header');
        foreach (['abc', '0', '"-1"', '99999999999'] as $header) {
            $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'x'], ['HTTP_IF_MATCH' => $header]);
            $this->assertRefused(400, 'INPUT_002');
        }
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'x', 'base_revision' => 0]);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Any'], ['HTTP_IF_MATCH' => '*']);
        self::assertResponseIsSuccessful();
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['name' => 'Weak'], ['HTTP_IF_MATCH' => 'W/"2"']);
        self::assertSame(3, $this->json()['revision']);
    }

    public function testBulkUpdatesEverythingInOneGoAndCountsIt(): void
    {
        $colleague = $this->user('colleague@example.com');
        $first     = $this->task($this->roadmap, 'First');
        $second    = $this->task($this->roadmap, 'Second');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [
            ['id' => $first->getId(), 'base_revision' => 1, 'description' => 'Versión propuesta: 1', 'status' => 'doing', 'tags' => ['repo:core-nowo', 'capa:src_new'], 'priority' => 2, 'due_date' => 1790000000000, 'assignees' => ['add' => ['uuid-' . $colleague->getId()]]],
            ['id' => $second->getId(), 'markdown_content' => '- [x] done'],
        ]], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        $tasks = $this->listOf('tasks');
        self::assertSame([$first->getId(), $second->getId()], array_map(static fn (array $task): string => $task['id'], $tasks));
        self::assertSame('Doing', $tasks[0]['status']['status']);
        self::assertSame(['capa:src_new', 'repo:core-nowo'], array_column($tasks[0]['tags'], 'name'));
        self::assertSame('high', $tasks[0]['priority']['priority']);
        self::assertSame(['uuid-' . $colleague->getId()], array_column($tasks[0]['assignees'], 'id'));
        self::assertSame('- [x] done', $tasks[1]['description']);
        self::assertSame(2, $tasks[1]['revision']);
        self::assertSame('bulk', RecordingTransitionPolicy::$asked[0]->via->operation);
        self::assertSame(['repo:core-nowo', 'capa:src_new'], RecordingTransitionPolicy::$asked[0]->tags);
        $audit = $this->audit();
        self::assertSame(['task.bulk_update', 'task.bulk_update'], array_map(static fn (TaskApiWriteEvent $event): string => $event->operation, $audit->events));
        self::assertCount(1, $audit->bulk);
        self::assertSame([2, 'mcp', 'tenant-a'], [$audit->bulk[0]->count, $audit->bulk[0]->channel, $audit->bulk[0]->tenantKey]);
        self::assertCount(1, $this->statusEvents);
    }

    public function testOneRefusalRollsBackEveryItemAndListsEveryFailure(): void
    {
        $first  = $this->task($this->roadmap, 'First');
        $second = $this->task($this->roadmap, 'Second', 1);
        $third  = $this->task($this->roadmap, 'Third');
        $this->send('PUT', '/api/tasks/v2/task/' . $third->getId(), ['name' => 'Third, changed']);

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [
            ['id' => $first->getId(), 'description' => 'would change'],
            ['id' => $second->getId(), 'status' => 'done'],
            ['id' => $third->getId(), 'base_revision' => 1, 'description' => 'stale'],
            ['id' => $first->getId() . 'x', 'description' => 'nobody'],
        ]]);
        $this->assertBulkFailures([[3, 'ACCESS_001', 404]]);

        // Reach first; once every task is reached, every check is listed.
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [
            ['id' => $first->getId(), 'description' => 'would change'],
            ['id' => $second->getId(), 'status' => 'done'],
            ['id' => $third->getId(), 'base_revision' => 1, 'description' => 'stale'],
            ['id' => $first->getId() . '', 'name' => 'unknown field'],
        ]]);
        $this->assertBulkFailures([[3, 'INPUT_002', 400]]);

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [
            ['id' => $first->getId(), 'description' => 'would change'],
            ['id' => $second->getId(), 'status' => 'done'],
            ['id' => $third->getId(), 'base_revision' => 1, 'description' => 'stale'],
        ]]);
        $this->assertBulkFailures([[1, 'STATUS_HUMANS_ONLY', 403], [2, 'TASK_409', 409]]);
        self::assertSame(2, $this->json()['failures'][1]['current_revision']);
        $this->em->clear();
        self::assertNull($this->em->find(Task::class, $first->getId())?->getDescription());
        self::assertSame('Doing', $this->em->find(Task::class, $second->getId())?->getColumn()?->getName());
        self::assertSame(2, $this->em->find(Task::class, $third->getId())?->getRevision());
        self::assertSame([], $this->audit()->bulk);
    }

    public function testBulkLimitsAndOneSpace(): void
    {
        $here  = $this->task($this->roadmap, 'Here');
        $there = $this->task($this->board('Other', tenantKey: 'tenant-b'), 'There');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => array_map(static fn (int $i): array => ['id' => 'task-' . $i], range(1, 51))]);
        $this->assertRefused(400, 'BULK_003');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => []]);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => 'a'], 'b']]);
        $this->assertRefused(400, 'INPUT_002');

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $here->getId(), 'description' => 'x'], ['id' => $there->getId(), 'description' => 'y']]]);
        $this->assertRefused(400, 'BULK_002');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $here->getId()], ['id' => $here->getId()]]]);
        $this->assertBulkFailures([[1, 'INPUT_002', 400]]);

        // Since 2.15.1 (REQ-SEC-006): a look-alike tenant is another space, so the two tasks are not one bulk update.
        ApiTestHost::$tenants[] = 'Tenant-A';
        // A request resets the manager: the fixtures are found again before the next one is created.
        $this->owner = $this->em->find(AppUser::class, $this->owner->getId()) ?? $this->owner;
        $lookAlike   = $this->task($this->board('Look-alike', tenantKey: 'Tenant-A'), 'There too');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $here->getId(), 'description' => 'x'], ['id' => $lookAlike->getId(), 'description' => 'y']]]);
        $this->assertRefused(400, 'BULK_002');
        $this->em->clear();
        self::assertNull($this->em->find(Task::class, $lookAlike->getId())?->getDescription());
    }

    public function testATaskOfAnotherTenantIsNotFoundInABulkUpdate(): void
    {
        ApiTestHost::$tenants = ['tenant-a'];
        $there                = $this->task($this->board('Other', tenantKey: 'tenant-b'), 'There');
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $there->getId(), 'description' => 'x']]]);
        $this->assertBulkFailures([[0, 'ACCESS_001', 404]]);
        $this->em->clear();
        self::assertNull($this->em->find(Task::class, $there->getId())?->getDescription());
    }

    public function testTheAuthorEditsTheirCommentAndEveryoneElseGets404(): void
    {
        $task    = $this->task($this->roadmap, 'Discussed');
        $mine    = new TaskComment($task, $this->owner, 'First draft');
        $other   = $this->user('other@example.com');
        $theirs  = new TaskComment($task, $other, 'Not yours');
        $foreign = new TaskComment($this->task($this->board('Foreign', tenantKey: 'tenant-c'), 'Elsewhere'), $this->owner, 'Out of reach');
        foreach ([$mine, $theirs, $foreign] as $comment) {
            $this->em->persist($comment);
        }
        $this->em->flush();

        $this->send('PUT', '/api/tasks/v2/comment/' . $mine->getId(), ['comment_text' => 'Final text', 'resolved' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame([['field' => 'resolved', 'reason' => 'not_supported']], $this->json()['warnings']);
        $this->em->clear();
        self::assertSame('Final text', $this->em->find(TaskComment::class, $mine->getId())?->getBody());
        self::assertSame('comment.update', $this->audit()->events[0]->operation);

        foreach ([$theirs->getId(), $foreign->getId(), 'missing', $mine->getId() . '%20', strtoupper($mine->getId())] as $id) {
            $this->send('PUT', '/api/tasks/v2/comment/' . $id, ['comment_text' => 'Hijack']);
            $this->assertRefused(404, 'ACCESS_001');
        }
        $this->send('PUT', '/api/tasks/v2/comment/' . $mine->getId(), ['comment_text' => '']);
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testCheckingAnItemAsksThePolicyAsAChecklistOperation(): void
    {
        $task = $this->task($this->roadmap, 'With steps');
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/checklist', ['name' => 'Steps']);
        $checklist = $this->json()['checklist']['id'];
        $this->send('POST', '/api/tasks/v2/checklist/' . $checklist . '/checklist_item', ['name' => 'Tick me'], [], 'bt_mcp');
        $item = $this->json()['checklist']['items'][0]['id'];
        // Strict by default: an agent may not tick an item into a humans-only done column either.
        $this->send('PUT', '/api/tasks/v2/checklist/' . $checklist . '/checklist_item/' . $item, ['resolved' => true], [], 'bt_mcp');
        $this->assertRefused(403, 'STATUS_HUMANS_ONLY');
        self::assertSame('checklist', RecordingTransitionPolicy::$asked[1]->via->operation);
        // humans_only_policy.allow_checklist: true lets it; a host rule may still refuse the way back.
        RecordingTransitionPolicy::$allowChecklist = true;
        $this->send('PUT', '/api/tasks/v2/checklist/' . $checklist . '/checklist_item/' . $item, ['resolved' => true], [], 'bt_mcp');
        self::assertResponseIsSuccessful();
        RecordingTransitionPolicy::$wipFull = ['To do'];
        $this->send('PUT', '/api/tasks/v2/checklist/' . $checklist . '/checklist_item/' . $item, ['resolved' => false], [], 'bt_mcp');
        $this->assertRefused(403, 'WIP_LIMIT');
    }

    public function testAStaleCopyInMemoryIsReloadedBeforeTheWrite(): void
    {
        $task = $this->task($this->roadmap, 'Edited elsewhere');
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET revision = 5, title = ? WHERE id = ?', ['Newer title', $task->getId()]);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['description' => 'Mine', 'base_revision' => 5]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Newer title', 6], [$this->json()['name'], $this->json()['revision']]);
    }

    public function testBulkRemovesAssigneesRefusesUnknownFieldsAndKeepsGoingWhenTheAuditFails(): void
    {
        $colleague = $this->user('colleague@example.com');
        $first     = $this->task($this->roadmap, 'First');
        $second    = $this->task($this->roadmap, 'Second');
        $first->addMember(new TaskMember($first, $colleague, TaskMemberRole::Assignee));
        $first->addMember(new TaskMember($first, $this->owner, TaskMemberRole::Watcher));
        $this->em->flush();

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $first->getId()], ['id' => $second->getId(), 'name' => 'Renamed']]]);
        $this->assertBulkFailures([[1, 'INPUT_002', 400]]);
        self::assertSame('Invalid name: not taken by a bulk update', $this->json()['failures'][0]['err']);
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => 'all']);
        $this->assertRefused(400, 'INPUT_002');

        $this->audit()->fail = true;
        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $first->getId(), 'assignees' => ['rem' => ['uuid-' . $colleague->getId(), 'uuid-' . $this->owner->getId()]]]]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->listOf('tasks')[0]['assignees']);
        self::assertCount(1, $this->listOf('tasks')[0]['watchers']);
    }

    public function testACommentEditMentionsAsOnCreate(): void
    {
        $colleague = $this->user('colleague@example.com');
        $task      = $this->task($this->roadmap, 'Discussed');
        $task->addMember(new TaskMember($task, $colleague, TaskMemberRole::Watcher));
        $mine = new TaskComment($task, $this->owner, 'First draft');
        $this->em->persist($mine);
        $this->em->flush();
        // The board's tasks (and their members, the mention candidates) as a request loads them.
        $this->em->clear();
        $this->send('PUT', '/api/tasks/v2/comment/' . $mine->getId(), ['comment_text' => 'Ping @colleague@example.com']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame(['colleague@example.com'], array_column($this->em->find(TaskComment::class, $mine->getId())?->getMentions() ?? [], 'label'));
    }

    /**
     * `BULK_001` is always 409; each item keeps its own status and ECODE in `failures`.
     *
     * @param list<array{0: int, 1: string, 2: int}> $failures index, ECODE, the item's own HTTP status
     */
    private function assertBulkFailures(array $failures): void
    {
        self::assertResponseStatusCodeSame(409);
        $json = $this->json();
        self::assertSame('BULK_001', $json['ECODE']);
        self::assertSame($failures, array_map(static fn (array $failure): array => [$failure['index'], $failure['ECODE'], $failure['status']], $json['failures']));
    }

    private function column(string $name): BoardColumn
    {
        foreach ($this->roadmap->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }
        self::fail('No column ' . $name);
    }

    private function audit(): ApiAuditRecorder
    {
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);

        return $audit;
    }
}
