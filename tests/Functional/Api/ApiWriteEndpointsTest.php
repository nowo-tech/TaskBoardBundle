<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskMentionEvent;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingQuotaListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function str_repeat;

/**
 * Spec 213 S4: what each write does, through the services the UI uses (FR-011): their events, limits and history.
 */
final class ApiWriteEndpointsTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private AppUser $colleague;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->colleague      = $this->user('colleague@example.com');
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
        foreach ([TaskBoardEvents::TASK_CREATED, TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_COMPLETED, TaskBoardEvents::TASK_REOPENED, TaskBoardEvents::TASK_MEMBER_ADDED, TaskBoardEvents::TASK_COMMENT, TaskBoardEvents::TASK_ERASED] as $name) {
            $this->dispatcher()->addListener($name, function () use ($name): void {
                $this->events[] = $name;
            });
        }
    }

    public function testCreateATaskAsTheUiDoesAndWarnAboutWhatIsIgnored(): void
    {
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', [
            'name'             => '  Ship it  ',
            'markdown_content' => '**Now**',
            'status'           => 'doing',
            'priority'         => 1,
            'due_date'         => 1790000000000,
            'start_date'       => '1789000000000',
            'time_estimate'    => 5400000,
            'tags'             => ['api'],
            'assignees'        => ['uuid-' . $this->colleague->getId()],
            'points'           => 3,
            'mystery'          => true,
        ]);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame('Ship it', $json['name']);
        self::assertSame('**Now**', $json['description']);
        self::assertSame('Doing', $json['status']['status']);
        self::assertSame('urgent', $json['priority']['priority']);
        self::assertSame('1790000000000', $json['due_date']);
        self::assertSame('5400000', $json['time_estimate']);
        self::assertSame(['uuid-' . $this->colleague->getId()], array_column($json['assignees'], 'id'));
        self::assertSame([['field' => 'mystery', 'reason' => 'unknown'], ['field' => 'points', 'reason' => 'not_supported']], $json['warnings']);
        self::assertSame([TaskBoardEvents::TASK_CREATED, TaskBoardEvents::TASK_MEMBER_ADDED], $this->events);
        $audit = $this->audit()->events;
        self::assertCount(1, $audit);
        self::assertSame(['task.create', 'tenant-a', $this->roadmap->getId(), $json['id']], [$audit[0]->operation, $audit[0]->tenantKey, $audit[0]->boardId, $audit[0]->taskId]);
        self::assertSame('task_create', $this->quota()->asked[0]->action);
    }

    public function testASubtaskOnTheSameBoardOnly(): void
    {
        $parent = $this->task($this->roadmap, 'Parent');
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Child', 'parent' => $parent->getId()]);
        self::assertSame($parent->getId(), $this->json()['parent']);

        $elsewhere = $this->task($this->board('Other', tenantKey: 'tenant-a'), 'Elsewhere');
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Child', 'parent' => $elsewhere->getId()]);
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testCreateRefusalsChangeNothing(): void
    {
        $path = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';
        foreach ([
            [['name' => ''], 400, 'INPUT_002'],
            [['name' => str_repeat('x', 256)], 400, 'INPUT_002'],
            [[], 400, 'INPUT_002'],
            [['name' => 'x', 'status' => 'nowhere'], 400, 'INPUT_002'],
            [['name' => 'x', 'priority' => 9], 400, 'INPUT_002'],
            [['name' => 'x', 'priority' => 'high'], 400, 'INPUT_002'],
            [['name' => 'x', 'start_date' => 1790000000000, 'due_date' => 1789000000000], 400, 'INPUT_002'],
            [['name' => 'x', 'assignees' => ['nobody']], 400, 'INPUT_002'],
            [['name' => 'x', 'assignees' => 'uuid-1'], 400, 'INPUT_002'],
            [['name' => 'x', 'tags' => [['nested']]], 400, 'INPUT_002'],
            [['name' => ['x']], 400, 'INPUT_002'],
            [['name' => 'x', 'due_date' => 'tomorrow'], 400, 'INPUT_002'],
            [['name' => 'x', 'tags' => [str_repeat('t', 65)]], 400, 'INPUT_002'],
            [['name' => 'x', 'tags' => array_map(static fn (int $i): string => 't' . $i, range(1, 21))], 400, 'INPUT_002'],
            [['name' => 'x', 'tags' => ['']], 400, 'INPUT_002'],
            ['{oops', 400, 'INPUT_001'],
            ['[1, 2]', 400, 'INPUT_001'],
            ['"text"', 400, 'INPUT_001'],
            [str_repeat(' ', 1048577), 400, 'INPUT_001'],
        ] as [$body, $status, $ecode]) {
            $this->send('POST', $path, $body);
            $this->assertRefused($status, $ecode);
        }
        self::assertSame([], $this->em->getRepository(Task::class)->findAll());
        self::assertSame([], $this->audit()->events);
    }

    public function testThePlanLimitRefusesAndSoDoesNoLimitAtAll(): void
    {
        $this->quota()->deny = ['task_create'];
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Over']);
        $this->assertRefused(403, 'PLAN_002');
        self::assertSame('Your plan is full.', $this->json()['err']);

        $this->quota()->deny = [];
        foreach ($this->dispatcher()->getListeners(TaskBoardEvents::QUOTA_CHECK) as $listener) {
            $this->dispatcher()->removeListener(TaskBoardEvents::QUOTA_CHECK, $listener);
        }
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Unchecked']);
        $this->assertRefused(403, 'PLAN_002');
        self::assertSame([], $this->em->getRepository(Task::class)->findAll());
    }

    public function testNoAuditMeansNoWrite(): void
    {
        foreach ($this->dispatcher()->getListeners(TaskBoardEvents::API_WRITE) as $listener) {
            $this->dispatcher()->removeListener(TaskBoardEvents::API_WRITE, $listener);
        }
        $task = $this->task($this->roadmap, 'Untouched');

        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Refused']);
        $this->assertRefused(503, 'AUDIT_001');
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId());
        $this->assertRefused(503, 'AUDIT_001');
        self::assertCount(1, $this->em->getRepository(Task::class)->findAll());
    }

    public function testAFailingAuditListenerDoesNotUndoTheWrite(): void
    {
        $this->audit()->fail = true;

        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'Saved anyway']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->em->getRepository(Task::class)->findAll());
    }

    public function testUpdateMovesThroughTheColumnServiceSoCompletionFollows(): void
    {
        $task = $this->task($this->roadmap, 'Move me');
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Assignee));
        $this->em->flush();
        $this->events = [];

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), [
            'status'          => 'Done',
            'name'            => 'Moved',
            'priority'        => null,
            'due_date'        => null,
            'time_estimate'   => null,
            'assignees'       => ['add' => [], 'rem' => ['uuid-' . $this->colleague->getId()]],
            'watchers'        => ['add' => ['uuid-' . $this->owner->getId()]],
            'group_assignees' => ['add' => []],
        ]);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame(['Moved', 'Done', 'closed'], [$json['name'], $json['status']['status'], $json['status']['type']]);
        self::assertNotNull($json['date_done'], 'the done column completes the task, as in the UI');
        self::assertSame([], $json['assignees']);
        self::assertSame(['uuid-' . $this->owner->getId()], array_column($json['watchers'], 'id'));
        self::assertSame([['field' => 'group_assignees', 'reason' => 'not_supported']], $json['warnings']);
        self::assertContains(TaskBoardEvents::TASK_COMPLETED, $this->events);
        self::assertContains(TaskBoardEvents::TASK_MOVED, $this->events);
        self::assertSame(['status', 'fields', 'assignees', 'watchers'], end($this->audit()->events)->changedFields);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['tags' => ['ignored']]);
        self::assertSame([['field' => 'tags', 'reason' => 'not_supported']], $this->json()['warnings'], 'tags go through the tag endpoints');
        self::assertSame([], $this->json()['tags']);

        $this->events = [];
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'To do']);
        self::assertContains(TaskBoardEvents::TASK_REOPENED, $this->events);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'To do', 'priority' => 4]);
        self::assertSame('low', $this->json()['priority']['priority']);
        self::assertSame(['fields'], end($this->audit()->events)->changedFields, 'the same status is no move');
    }

    public function testArchiveAndRestore(): void
    {
        $task = $this->task($this->roadmap, 'Shelved');

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['archived' => true]);
        self::assertTrue($this->json()['archived']);
        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['archived' => false]);
        self::assertFalse($this->json()['archived']);
        foreach ([['archived' => 'yes'], ['assignees' => 'x'], ['assignees' => ['add' => [], 'swap' => []]], ['watchers' => ['add' => 'x']]] as $body) {
            $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), $body);
            $this->assertRefused(400, 'INPUT_002');
        }
    }

    public function testMyTasksChangesOnlyStatusDatesAndDescriptionOfItsOwnTasks(): void
    {
        $own    = $this->task($this->roadmap, 'Own');
        $theirs = new Task(board: $this->roadmap, title: 'Theirs', creator: $this->colleague);
        $this->em->persist($theirs);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->send('PUT', '/api/tasks/v2/task/' . $own->getId(), ['status' => 'Doing', 'description' => 'Mine', 'due_date' => 1790000000000, 'start_date' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Doing', 'Mine'], [$this->json()['status']['status'], $this->json()['description']]);

        foreach ([['name' => 'x'], ['priority' => 1], ['time_estimate' => 1], ['assignees' => ['add' => ['uuid-' . $this->colleague->getId()]]], ['watchers' => ['add' => []]], ['archived' => true]] as $body) {
            $this->send('PUT', '/api/tasks/v2/task/' . $own->getId(), $body);
            $this->assertRefused(403, 'TOKEN_001');
        }
        $this->send('PUT', '/api/tasks/v2/task/' . $theirs->getId(), ['status' => 'Doing']);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('POST', '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task', ['name' => 'New']);
        $this->assertRefused(403, 'TOKEN_001');
    }

    public function testDeleteErasesTheSubtreeAndKeepsTheTime(): void
    {
        $task  = $this->task($this->roadmap, 'Gone');
        $child = $this->task($this->roadmap, 'Gone too', parent: $task);
        $entry = new TaskTimeEntry($child, $this->owner, 45, new DateTimeImmutable('2026-11-01'));
        $child->addTimeEntry($entry);
        $this->em->persist($entry);
        $this->em->flush();

        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId(), ['force' => true]);

        self::assertSame(['warnings' => [['field' => 'force', 'reason' => 'unknown']]], $this->json());
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Task::class)->findAll());
        $kept = $this->em->find(TaskTimeEntry::class, $entry->getId());
        self::assertSame(45, $kept?->getMinutes());
        self::assertTrue($kept?->isDetached());
        self::assertSame([TaskBoardEvents::TASK_ERASED, TaskBoardEvents::TASK_ERASED], $this->events);
    }

    public function testCommentsTagsAndFields(): void
    {
        $task = $this->task($this->roadmap, 'Talked about');

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/comment', ['comment_text' => 'Looks good', 'notify_all' => true]);
        self::assertResponseIsSuccessful();
        $comment = $this->em->getRepository(TaskComment::class)->findOneBy(['task' => $task]);
        self::assertSame(['id' => $comment?->getId(), 'hist_id' => $comment?->getId(), 'date' => (int) $comment?->getCreatedAt()->format('Uv'), 'warnings' => [['field' => 'notify_all', 'reason' => 'not_supported']]], $this->json());
        self::assertContains(TaskBoardEvents::TASK_COMMENT, $this->events);
        // Mentions as in the UI's box: "@Label" of a candidate (here a member of a task of the board).
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Watcher));
        $this->em->flush();
        $mentioned = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_MENTION, static function (TaskMentionEvent $event) use (&$mentioned): void {
            $mentioned = [...$mentioned, ...$event->getMentionedUsers()];
        });
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/comment', ['comment_text' => 'Ping @colleague@example.com']);
        self::assertSame(['colleague@example.com'], array_map(static fn (object $user): string => $user instanceof AppUser ? $user->getEmail() : '', $mentioned));
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/comment', ['comment_text' => '']);
        $this->assertRefused(400, 'INPUT_002');

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/tag/Urgent');
        self::assertSame([], $this->json());
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/tag/api');
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId() . '/tag/URGENT');
        $this->em->clear();
        self::assertSame(['api'], $this->em->find(Task::class, $task->getId())?->getTags());
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/tag/' . str_repeat('t', 65));
        $this->assertRefused(400, 'INPUT_002');
        // A body these endpoints do not read is warned about, never silently dropped.
        $this->send('DELETE', '/api/tasks/v2/task/' . $task->getId() . '/tag/api', ['color' => 'red']);
        self::assertSame(['warnings' => [['field' => 'color', 'reason' => 'unknown']]], $this->json());
        // The task form's limit: at most 20 tags.
        $many       = TaskFormData::fromTask($task);
        $many->tags = array_map(static fn (int $i): string => 't' . $i, range(1, 20));
        $this->em->find(Task::class, $task->getId())?->setTags($many->tags);
        $this->em->flush();
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/tag/one-more');
        $this->assertRefused(400, 'INPUT_002');

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/field/any', ['value' => 1]);
        $this->assertRefused(409, 'FIELD_001');
    }

    public function testDependenciesAndLinks(): void
    {
        $task      = $this->task($this->roadmap, 'Task');
        $blocker   = $this->task($this->roadmap, 'Blocker');
        $waiting   = $this->task($this->roadmap, 'Waiting');
        $elsewhere = $this->task($this->board('Other', tenantKey: 'tenant-a'), 'Elsewhere');
        $base      = '/api/tasks/v2/task/' . $task->getId();

        $this->send('POST', $base . '/dependency', ['depends_on' => $blocker->getId()]);
        self::assertResponseIsSuccessful();
        $this->send('POST', $base . '/dependency', ['dependency_of' => $waiting->getId()]);
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertEqualsCanonicalizing([[$task->getId(), $blocker->getId()], [$waiting->getId(), $task->getId()]], array_map(static fn (array $d): array => [$d['task_id'], $d['depends_on']], $this->json()['dependencies']));

        $this->send('POST', $base . '/dependency', ['depends_on' => $blocker->getId()]);
        $this->assertRefused(400, 'LINK_001');
        $this->send('POST', '/api/tasks/v2/task/' . $blocker->getId() . '/dependency', ['depends_on' => $waiting->getId()]);
        $this->assertRefused(400, 'LINK_001');
        $this->send('POST', $base . '/dependency', ['depends_on' => $blocker->getId(), 'dependency_of' => $waiting->getId()]);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', $base . '/dependency', []);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('POST', $base . '/dependency', ['depends_on' => $elsewhere->getId()]);
        $this->assertRefused(400, 'LINK_002');

        $this->send('DELETE', $base . '/dependency?dependency_of=' . $blocker->getId());
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('DELETE', $base . '/dependency?depends_on=' . $blocker->getId());
        self::assertResponseIsSuccessful();
        $this->send('DELETE', $base . '/dependency?dependency_of=' . $waiting->getId());
        self::assertResponseIsSuccessful();

        $this->send('POST', $base . '/link/' . $blocker->getId());
        self::assertSame([$blocker->getId()], array_column($this->json()['task']['linked_tasks'], 'link_id'));
        $this->send('DELETE', $base . '/link/' . $blocker->getId());
        self::assertSame([], $this->json()['task']['linked_tasks']);
        $this->send('DELETE', $base . '/link/' . $blocker->getId());
        $this->assertRefused(404, 'ACCESS_001');
        self::assertSame(0, $this->em->getRepository(TaskDependency::class)->count([]));
    }

    public function testChecklistsAreTaggedSubtasks(): void
    {
        $task = $this->task($this->roadmap, 'Release');
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/checklist', ['name' => 'Steps']);
        $checklist = $this->json()['checklist'];
        self::assertSame([TaskApiReadSerializer::checklistId($task->getId(), 'Steps'), 'Steps', []], [$checklist['id'], $checklist['name'], $checklist['items']]);
        $base = '/api/tasks/v2/checklist/' . $checklist['id'];

        $this->send('POST', $base . '/checklist_item', ['name' => 'Write', 'assignee' => 'uuid-' . $this->owner->getId()]);
        $this->send('POST', $base . '/checklist_item', ['name' => 'Test']);
        $items = $this->json()['checklist']['items'];
        self::assertSame(['Write', 'Test'], array_column($items, 'name'));
        self::assertSame('uuid-' . $this->owner->getId(), $items[0]['assignee']['id']);
        $this->send('POST', $base . '/checklist_item', ['name' => 'Theirs', 'assignee' => 'uuid-' . $this->colleague->getId()]);
        $this->assertRefused(403, 'TOKEN_001');

        $this->send('PUT', $base . '/checklist_item/' . $items[0]['id'], ['resolved' => true, 'name' => 'Written']);
        self::assertSame([1, 1], [$this->json()['checklist']['resolved'], $this->json()['checklist']['unresolved']]);
        self::assertSame('Written', $this->json()['checklist']['items'][0]['name']);
        $this->send('PUT', $base . '/checklist_item/' . $items[0]['id'], ['resolved' => false]);
        self::assertSame(0, $this->json()['checklist']['resolved']);

        $this->send('PUT', $base, []);
        self::assertSame([], $this->json(), 'no name: nothing to rename');
        $this->send('POST', $base . '/checklist_item', ['name' => 'Nobody', 'assignee' => 'nobody']);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('PUT', $base . '/checklist_item/' . $items[1]['id'], ['assignee' => 'uuid-' . $this->owner->getId()]);
        self::assertSame('uuid-' . $this->owner->getId(), $this->json()['checklist']['items'][1]['assignee']['id']);
        $this->send('PUT', $base, ['name' => 'Release steps', 'position' => 1]);
        self::assertSame([['field' => 'position', 'reason' => 'not_supported']], $this->json()['warnings']);
        $renamed = '/api/tasks/v2/checklist/' . TaskApiReadSerializer::checklistId($task->getId(), 'Release steps');
        $this->send('DELETE', $renamed . '/checklist_item/' . $items[1]['id']);
        self::assertSame([], $this->json());
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame(['Written'], array_column($this->json()['checklists'][0]['items'], 'name'));

        $this->send('DELETE', $renamed);
        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertSame([], $this->json()['checklists']);
        $this->em->clear();
        self::assertCount(3, $this->em->getRepository(Task::class)->findAll(), 'archived, not deleted');

        foreach (['/api/tasks/v2/checklist/nothing', '/api/tasks/v2/checklist/cl_bm90aGluZw', $renamed . '/checklist_item/nothing'] as $path) {
            $this->send('PUT', $path, ['name' => 'x']);
            $this->assertRefused(404, 'ACCESS_001');
        }
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/checklist', ['name' => '']);
        $this->assertRefused(400, 'INPUT_002');
    }

    private function audit(): ApiAuditRecorder
    {
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);

        return $audit;
    }

    private function quota(): RecordingQuotaListener
    {
        $quota = self::getContainer()->get('app.quota_listener');
        self::assertInstanceOf(RecordingQuotaListener::class, $quota);

        return $quota;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
