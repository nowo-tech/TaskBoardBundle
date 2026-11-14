<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function count;

/**
 * Spec 213 S3: what each read endpoint answers, in ClickUp's shape, and its filters.
 */
final class ApiReadEndpointsTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private AppUser $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->colleague      = $this->user('colleague@example.com');
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testATeamListsItsSpacesAndAnotherTeamNone(): void
    {
        $this->get('/api/tasks/v2/team/team-1/space');
        self::assertResponseIsSuccessful();
        self::assertSame(['space-tenant-a', 'space-tenant-b'], $this->idsOf('spaces'));

        $this->get('/api/tasks/v2/team/team-2/space');
        self::assertSame([], $this->listOf('spaces'));

        $this->get('/api/tasks/v2/team/team-9/space');
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testASpaceAndItsFolders(): void
    {
        $this->get('/api/tasks/v2/space/space-tenant-a');
        self::assertResponseIsSuccessful();
        self::assertSame('space-tenant-a', $this->json()['id']);
        self::assertSame('Space tenant-a', $this->json()['name']);
        self::assertFalse($this->json()['private']);

        $this->get('/api/tasks/v2/space/space-tenant-a/folder');
        self::assertSame(['folders' => []], $this->json());

        $this->get('/api/tasks/v2/space/space-nothing');
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testListsCountOpenTasksAndShowColumnsAsStatuses(): void
    {
        $this->task($this->roadmap, 'Open');
        $this->task($this->roadmap, 'Also open');
        $this->em->persist((new Task(board: $this->roadmap, title: 'Archived', creator: $this->owner))->archive());
        $this->em->flush();
        $this->board('Elsewhere', tenantKey: 'tenant-b');

        $this->get('/api/tasks/v2/space/space-tenant-a/list');

        self::assertResponseIsSuccessful();
        $lists = $this->listOf('lists');
        self::assertCount(1, $lists);
        self::assertSame($this->roadmap->getId(), $lists[0]['id']);
        self::assertSame(2, $lists[0]['task_count']);
        self::assertSame(['id' => 'space-tenant-a', 'name' => 'Space tenant-a', 'access' => true], $lists[0]['space']);
        self::assertSame(['To do' => 'open', 'Doing' => 'custom', 'Done' => 'closed'], array_column($lists[0]['statuses'], 'type', 'status'));

        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId());
        self::assertSame($lists[0], $this->json());
    }

    public function testATaskInClickUpsShape(): void
    {
        $task = $this->task($this->roadmap, 'Ship the API', 1);
        $task->setDescription('**Bold** text')->setPriority(TaskPriority::High)->setTags(['api', 'v2'])->setDueAt(new DateTimeImmutable('@1790000000'))->setEstimatedMinutes(90);
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Assignee));
        $task->addMember(new TaskMember(task: $task, user: $this->owner, memberRole: TaskMemberRole::Watcher));
        $blocker = $this->task($this->roadmap, 'Blocker');
        $related = $this->task($this->roadmap, 'Related');
        $this->em->persist(new TaskDependency($blocker, $task, TaskDependencyType::Blocks));
        $this->em->persist(new TaskDependency($task, $related, TaskDependencyType::Related));
        $item = new Task(board: $this->roadmap, title: 'Write the docs', creator: $this->owner, parent: $task, tags: ['checklist:Release']);
        $this->em->persist($item->markCompleted());
        $this->em->persist(new Task(board: $this->roadmap, title: 'Test it', creator: $this->owner, parent: $task, position: 1, tags: ['checklist:Release']));
        $this->em->persist(new Task(board: $this->roadmap, title: 'A plain subtask', creator: $this->owner, parent: $task));
        $this->em->persist(new TaskTimeEntry($task, $this->owner, 30, new DateTimeImmutable('2026-11-01')));
        $this->em->flush();

        $this->get('/api/tasks/v2/task/' . $task->getId() . '?include_markdown_description=true&include_subtasks=true');

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame($task->getId(), $json['id']);
        self::assertSame('Ship the API', $json['name']);
        self::assertSame('**Bold** text', $json['description']);
        self::assertSame('**Bold** text', $json['markdown_description']);
        self::assertSame(['id' => $this->roadmap->getColumns()->get(1)?->getId(), 'status' => 'Doing', 'color' => null, 'orderindex' => 1, 'type' => 'custom', 'icon' => null, 'humans_only' => false], $json['status']);
        self::assertSame(['id' => '2', 'priority' => 'high', 'color' => '#f8ae00', 'orderindex' => '2'], $json['priority']);
        self::assertSame('1790000000000', $json['due_date']);
        self::assertSame('5400000', $json['time_estimate']);
        self::assertSame(1800000, $json['time_spent']);
        self::assertSame([['name' => 'api', 'tag_fg' => null, 'tag_bg' => null], ['name' => 'v2', 'tag_fg' => null, 'tag_bg' => null]], $json['tags']);
        self::assertSame('uuid-' . $this->owner->getId(), $json['creator']['id']);
        self::assertSame(['uuid-' . $this->colleague->getId()], array_column($json['assignees'], 'id'));
        self::assertSame(['uuid-' . $this->owner->getId()], array_column($json['watchers'], 'id'));
        self::assertSame([['task_id' => $task->getId(), 'depends_on' => $blocker->getId(), 'type' => 1]], array_map(static fn (array $d): array => array_diff_key($d, ['date_created' => 0]), $json['dependencies']));
        self::assertSame([$related->getId()], array_column($json['linked_tasks'], 'link_id'));
        self::assertCount(1, $json['checklists']);
        self::assertSame(TaskApiReadSerializer::checklistId($task->getId(), 'Release'), $json['checklists'][0]['id']);
        self::assertSame(['Release', 1, 1], [$json['checklists'][0]['name'], $json['checklists'][0]['resolved'], $json['checklists'][0]['unresolved']]);
        self::assertSame(['Write the docs', 'Test it'], array_column($json['checklists'][0]['items'], 'name'));
        self::assertCount(3, $json['subtasks']);
        self::assertSame(['id' => $this->roadmap->getId(), 'name' => 'Roadmap', 'access' => true], $json['list']);
        self::assertSame(['id' => 'space-tenant-a'], $json['space']);
        self::assertSame('team-1', $json['team_id']);
        self::assertSame([], $json['custom_fields']);

        // The task it blocks sees the other side.
        $this->get('/api/tasks/v2/task/' . $blocker->getId());
        self::assertSame(0, $this->json()['dependencies'][0]['type']);
        self::assertArrayNotHasKey('markdown_description', $this->json());
    }

    public function testListTasksFilters(): void
    {
        $done = $this->task($this->roadmap, 'Done', 2);
        $done->markCompleted();
        $doing = $this->task($this->roadmap, 'Doing it', 1);
        $doing->setTags(['Urgent'])->setDueAt(new DateTimeImmutable('@1800000000'));
        $doing->addMember(new TaskMember(task: $doing, user: $this->colleague, memberRole: TaskMemberRole::Assignee));
        $todo = $this->task($this->roadmap, 'To do');
        $sub  = $this->task($this->roadmap, 'Sub', parent: $todo);
        $this->em->persist($archived = (new Task(board: $this->roadmap, title: 'Archived', creator: $this->owner))->archive());
        $this->em->flush();
        $base = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';

        $this->get($base);
        self::assertEqualsCanonicalizing([$doing->getId(), $todo->getId()], $this->idsOf('tasks'), 'open, top-level, not archived');
        self::assertTrue($this->json()['last_page']);

        $this->get($base . '?include_closed=true&subtasks=true');
        self::assertEqualsCanonicalizing([$done->getId(), $doing->getId(), $todo->getId(), $sub->getId()], $this->idsOf('tasks'));

        $this->get($base . '?archived=true');
        self::assertSame([$archived->getId()], $this->idsOf('tasks'));

        $this->get($base . '?statuses[]=DOING');
        self::assertSame([$doing->getId()], $this->idsOf('tasks'));

        $this->get($base . '?assignees[]=uuid-' . $this->colleague->getId());
        self::assertSame([$doing->getId()], $this->idsOf('tasks'));

        $this->get($base . '?assignees[]=nobody');
        self::assertSame([], $this->idsOf('tasks'));

        $this->get($base . '?tags[]=urgent');
        self::assertSame([$doing->getId()], $this->idsOf('tasks'));

        $this->get($base . '?due_date_gt=1790000000000');
        self::assertSame([$doing->getId()], $this->idsOf('tasks'));

        $this->get($base . '?due_date_lt=1790000000000');
        self::assertSame([], $this->idsOf('tasks'));

        $this->get($base . '?order_by=id');
        $ids = $this->idsOf('tasks');
        $this->get($base . '?order_by=id&reverse=true');
        self::assertSame(array_reverse($ids), $this->idsOf('tasks'));

        foreach (['page=x', 'page=-1', 'order_by=title', 'reverse=maybe', 'due_date_gt=soon', 'statuses=x&statuses[a][]=1'] as $bad) {
            $this->get($base . '?' . $bad);
            if ($bad === 'statuses=x&statuses[a][]=1') {
                self::assertResponseIsSuccessful('a nested value is no status');
                continue;
            }
            $this->assertRefused(400, 'INPUT_002');
        }

        $this->get($base . '?statuses=Doing');
        self::assertSame([$doing->getId()], $this->idsOf('tasks'), 'a single value is a list of one');
        $this->get($base . '?statuses=Doing&tags=nothing');
        self::assertSame([], $this->idsOf('tasks'));
        $this->get($base . '?assignees=x&tags[]=urgent&tags[]=');
        self::assertSame([], $this->idsOf('tasks'));
    }

    public function testAHundredPerPage(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->em->persist(new Task(board: $this->roadmap, title: 'Task ' . $i, creator: $this->owner, position: $i));
        }
        $this->em->flush();
        $base = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task';

        $this->get($base);
        self::assertCount(100, $this->listOf('tasks'));
        self::assertFalse($this->json()['last_page']);
        $first = $this->idsOf('tasks');

        $this->get($base . '?page=1');
        self::assertCount(1, $this->listOf('tasks'));
        self::assertTrue($this->json()['last_page']);
        self::assertNotContains($this->idsOf('tasks')[0], $first);

        $this->get($base . '?page=1&tags[]=nothing');
        self::assertSame([], $this->listOf('tasks'));
    }

    public function testTeamTasksAcrossBoardsNarrowedBySpaceAndList(): void
    {
        $here      = $this->task($this->roadmap, 'Here');
        $elsewhere = $this->board('Elsewhere', tenantKey: 'tenant-b');
        $there     = $this->task($elsewhere, 'There');

        $this->get('/api/tasks/v2/team/team-1/task');
        self::assertEqualsCanonicalizing([$here->getId(), $there->getId()], $this->idsOf('tasks'));

        $this->get('/api/tasks/v2/team/team-1/task?space_ids[]=space-tenant-b');
        self::assertSame([$there->getId()], $this->idsOf('tasks'));

        $this->get('/api/tasks/v2/team/team-1/task?list_ids[]=' . $this->roadmap->getId());
        self::assertSame([$here->getId()], $this->idsOf('tasks'));

        $this->get('/api/tasks/v2/team/team-2/task');
        self::assertSame([], $this->idsOf('tasks'));
    }

    public function testCommentsNewestFirstTwentyFivePerPageWithoutDeletedOnes(): void
    {
        $task = $this->task($this->roadmap, 'Discussed');
        for ($i = 0; $i < 27; ++$i) {
            $this->em->persist(new TaskComment($task, $this->colleague, 'Comment ' . $i));
        }
        $this->em->persist((new TaskComment($task, $this->owner, 'Gone'))->markDeleted());
        $this->em->persist(new TaskComment($task, null, 'Erased author'));
        $this->em->flush();
        $base = '/api/tasks/v2/task/' . $task->getId() . '/comment';

        $this->get($base);
        $first = $this->listOf('comments');
        self::assertCount(25, $first);
        self::assertNotContains('Gone', array_column($first, 'comment_text'));
        $oldest = $first[24];

        $this->get($base . '?start=' . $oldest['date'] . '&start_id=' . $oldest['id']);
        $second = $this->listOf('comments');
        self::assertCount(3, $second);
        self::assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $all = [...$first, ...$second];
        self::assertSame(1, count(array_filter($all, static fn (array $comment): bool => $comment['user'] === null)), 'an erased author is nobody');
        self::assertSame([['text' => $all[0]['comment_text']]], $all[0]['comment']);

        $this->get($base . '?start=' . $oldest['date']);
        self::assertResponseIsSuccessful();
        $this->get($base . '?start=yesterday');
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testMembersTagsFieldsAndTime(): void
    {
        $task = $this->task($this->roadmap, 'Staffed');
        $task->setTags(['b', 'A', 'a']);
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Assignee));
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Watcher));
        $this->em->flush();

        $this->get('/api/tasks/v2/task/' . $task->getId() . '/member');
        self::assertSame(['uuid-' . $this->colleague->getId()], $this->idsOf('members'));

        $this->get('/api/tasks/v2/space/space-tenant-a/tag');
        self::assertSame(['a', 'b'], array_column($this->listOf('tags'), 'name'));
        $this->get('/api/tasks/v2/space/space-tenant-b/tag');
        self::assertSame([], $this->listOf('tags'));

        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/field');
        self::assertSame(['fields' => []], $this->json());

        $this->get('/api/tasks/v2/team/team-1/time_entries?start_date=1789000000000&end_date=1791000000000&assignee=a,,b&task_id=' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertSame([[
            'id'          => 'entry-1',
            'task'        => ['id' => $task->getId()],
            'wid'         => 'team-1',
            'user'        => ['id' => 'uuid-' . $this->owner->getId()],
            'billable'    => false,
            'start'       => '1790000000000',
            'end'         => '1790003600000',
            'duration'    => '3600000',
            'description' => 'Review',
        ]], $this->listOf('data'));
        $query = ApiTestHost::$timeQueries[0];
        self::assertSame(['a', 'b'], $query->assigneeIds);
        self::assertSame(1789000000, $query->from?->getTimestamp());
        self::assertSame(1791000000, $query->to?->getTimestamp());

        $this->get('/api/tasks/v2/team/team-1/time_entries');
        self::assertNull(ApiTestHost::$timeQueries[1]->taskId);
        self::assertNull($this->listOf('data')[0]['task']);
        $this->get('/api/tasks/v2/team/team-1/time_entries?assignee[]=a');
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/team/team-1/time_entries?task_id=nothing');
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testAProfileTheHostLeavesOutIsNotShown(): void
    {
        $task = $this->task($this->roadmap, 'Private people');
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Assignee));
        $this->em->flush();
        ApiTestHost::$profilesEmpty = true;

        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertNull($this->json()['creator']);
        self::assertSame([], $this->json()['assignees']);
    }

    public function testSomeoneTheHostDoesNotKnowAndAnErasedCreatorAreNobody(): void
    {
        $task = $this->task($this->roadmap, 'Known');
        $task->addMember(new TaskMember(task: $task, user: $this->colleague, memberRole: TaskMemberRole::Assignee));
        $this->em->persist(new Task(board: $this->roadmap, title: 'Orphan', creator: null));
        $this->em->flush();
        ApiTestHost::$unknownEmails = ['colleague@example.com'];

        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/task');

        $tasks = array_column($this->listOf('tasks'), null, 'name');
        self::assertSame([], $tasks['Known']['assignees']);
        self::assertSame('uuid-' . $this->owner->getId(), $tasks['Known']['creator']['id']);
        self::assertNull($tasks['Orphan']['creator']);
    }

    public function testBoardsWithoutATenantFormTheirOwnSpace(): void
    {
        ApiTestHost::$tenants = [null];
        $loose                = $this->board('No tenant');

        $this->get('/api/tasks/v2/space/space-none/list');

        self::assertSame([$loose->getId()], $this->idsOf('lists'));
    }

    public function testMyTasksSeesOnlyItsSubtasksAndTheChecklistItemsOfItsTask(): void
    {
        // Audit IA-M2 (the auditor's proof, turned), and the owner's exception for checklist items.
        $own    = $this->task($this->roadmap, 'Own parent');
        $hidden = new Task(board: $this->roadmap, title: 'Colleague secret subtask', creator: $this->colleague, parent: $own);
        $item   = new Task(board: $this->roadmap, title: 'Colleague checklist item', creator: $this->colleague, parent: $own, tags: ['checklist:Steps']);
        $this->em->persist($hidden);
        $this->em->persist($item);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->get('/api/tasks/v2/task/' . $hidden->getId());
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/task/' . $own->getId() . '?include_subtasks=true');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Colleague secret subtask', (string) $this->client->getResponse()->getContent());
        self::assertSame(['Colleague checklist item'], array_column($this->json()['subtasks'], 'name'));
        self::assertSame(['Colleague checklist item'], array_column($this->json()['checklists'][0]['items'], 'name'));

        $this->issueToken(TaskApiLevel::Read);
        $this->get('/api/tasks/v2/task/' . $own->getId() . '?include_subtasks=true');
        self::assertCount(2, $this->json()['subtasks'], 'a Read token sees every subtask');
    }

    public function testARelationToATaskTheTokenCannotOpenIsLeftOut(): void
    {
        // Audit IA-I7.
        $own    = $this->task($this->roadmap, 'Own');
        $theirs = new Task(board: $this->roadmap, title: 'Theirs', creator: $this->colleague);
        $mine   = $this->task($this->roadmap, 'Also mine');
        $this->em->persist($theirs);
        $this->em->persist(new TaskDependency($theirs, $own, TaskDependencyType::Blocks));
        $this->em->persist(new TaskDependency($own, $mine, TaskDependencyType::Related));
        $this->em->persist(new TaskDependency($own, $theirs, TaskDependencyType::Related));
        $this->em->flush();

        $this->issueToken(TaskApiLevel::MyTasks);
        $this->get('/api/tasks/v2/task/' . $own->getId());
        self::assertSame([], $this->json()['dependencies']);
        self::assertSame([$mine->getId()], array_column($this->json()['linked_tasks'], 'link_id'));
        self::assertStringNotContainsString($theirs->getId(), (string) $this->client->getResponse()->getContent());

        $this->issueToken(TaskApiLevel::Read);
        $this->get('/api/tasks/v2/task/' . $own->getId());
        self::assertCount(1, $this->json()['dependencies']);
        self::assertCount(2, $this->json()['linked_tasks']);
    }

    public function testTheTagFilterMatchesWholeTagsCaseAside(): void
    {
        $a = $this->task($this->roadmap, 'A');
        $a->setTags(['Front-end', '50%_done']);
        $b = $this->task($this->roadmap, 'B');
        $b->setTags(['front']);
        $c = $this->task($this->roadmap, 'C');
        $c->setTags(['niño', 'ops/infra']);
        $this->em->flush();
        $base = '/api/tasks/v2/list/' . $this->roadmap->getId() . '/task?tags[]=';

        $this->get($base . 'FRONT-END');
        self::assertSame(['A'], array_column($this->listOf('tasks'), 'name'));
        $this->get($base . 'front');
        self::assertSame(['B'], array_column($this->listOf('tasks'), 'name'), 'no partial match');
        $this->get($base . '50%25_done');
        self::assertSame(['A'], array_column($this->listOf('tasks'), 'name'), 'wildcards are literal');
        $this->get($base . '50x_done');
        self::assertSame([], $this->listOf('tasks'));
        $this->get($base . 'niño');
        self::assertSame(['C'], array_column($this->listOf('tasks'), 'name'));
        $this->get($base . 'ops/infra');
        self::assertSame(['C'], array_column($this->listOf('tasks'), 'name'));
        $this->get($base . 'front&tags[]=niño');
        self::assertEqualsCanonicalizing(['B', 'C'], array_column($this->listOf('tasks'), 'name'), 'any of the tags');
    }

    public function testAMyTasksTokenCountsOnlyItsOwn(): void
    {
        $this->task($this->roadmap, 'Mine');
        $theirs   = new Task(board: $this->roadmap, title: 'Theirs', creator: $this->colleague);
        $assigned = new Task(board: $this->roadmap, title: 'Assigned to me', creator: $this->colleague);
        $assigned->addMember(new TaskMember(task: $assigned, user: $this->owner, memberRole: TaskMemberRole::Assignee));
        $this->em->persist($theirs);
        $this->em->persist($assigned);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId());
        self::assertSame(2, $this->json()['task_count']);
        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/task');
        self::assertEqualsCanonicalizing(['Mine', 'Assigned to me'], array_column($this->listOf('tasks'), 'name'));
    }
}
