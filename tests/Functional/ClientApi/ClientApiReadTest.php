<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_map;
use function str_repeat;

/**
 * Since 3.4.0 (client API v1, §4.2, §5): the read routes, narrowed by the context and the person's access before paging.
 */
final class ClientApiReadTest extends ClientApiTestCase
{
    private TaskBoard $roadmap;

    private TaskBoard $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roadmap = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->acme    = $this->board('Acme plans', tenantKey: 'tenant-b');
        $this->board('Elsewhere', tenantKey: 'tenant-c');
    }

    public function testMeNamesThePersonTheContextsAndWhatTheApiOffers(): void
    {
        $this->call('GET', '/me');
        self::assertResponseIsSuccessful();
        $me = $this->json();
        self::assertSame(1, $me['apiVersion']);
        self::assertSame(['id' => 'uuid-' . $this->owner->getId(), 'name' => 'owner@example.com', 'initials' => 'O', 'avatarUrl' => null], $me['user']);
        self::assertSame(['task-board:read', 'task-board:write'], $me['scopes']);
        self::assertSame([['key' => 'personal', 'label' => 'Personal'], ['key' => 'org:acme', 'label' => 'Org org:acme']], $me['contexts']);
        self::assertSame(['key' => 'personal', 'label' => 'Personal'], $me['context']);
        self::assertFalse($me['features']['realtime']);
        self::assertSame(['low', 'normal', 'high', 'urgent'], $me['enums']['priorities']);
    }

    public function testBoardsAreTheContextsOnlyPagedByName(): void
    {
        $this->board('Backlog', tenantKey: 'tenant-a');
        $this->call('GET', '/boards?limit=1');
        self::assertResponseIsSuccessful();
        self::assertSame(['Backlog'], array_column($this->json()['boards'], 'title'));
        self::assertSame(['type' => 'space', 'id' => 'space-tenant-a', 'label' => 'Space tenant-a'], $this->json()['boards'][0]['group']);
        $cursor = $this->json()['nextCursor'];
        self::assertIsString($cursor);

        $this->call('GET', '/boards?limit=1&cursor=' . $cursor);
        self::assertSame(['Roadmap'], array_column($this->json()['boards'], 'title'));
        self::assertNull($this->json()['nextCursor']);

        $this->call('GET', '/boards?q=road');
        self::assertSame(['Roadmap'], array_column($this->json()['boards'], 'title'));

        $this->call('GET', '/boards', context: 'org:acme');
        self::assertSame(['Acme plans'], array_column($this->json()['boards'], 'title'));

        $this->call('GET', '/boards?cursor=not-a-cursor');
        $this->assertError(400, 'invalid_cursor');
        $this->call('GET', '/boards?limit=0');
        $this->assertError(400, 'invalid_limit');
        $this->call('GET', '/boards?limit=101');
        $this->assertError(400, 'invalid_limit');
    }

    /**
     * Audit ZCY-L1: a page that ends on a 255-character CJK name (765 bytes lowercased) hands out a cursor the next call
     * accepts (3.4.0 refused string values over 191 bytes: `400 invalid_cursor`).
     */
    public function testTheBoardListPagesPastALongName(): void
    {
        $long = str_repeat("\u{4E00}", 255);
        // The helper's slug is the name plus a suffix (over 255): this board gets a short slug.
        $board = $this->board('Long', tenantKey: 'tenant-a');
        $board->setName($long);
        $this->em->flush();
        $this->board("\u{9FA5}", tenantKey: 'tenant-a');
        $this->call('GET', '/boards?limit=2');
        self::assertResponseIsSuccessful();
        self::assertSame(['Roadmap', $long], array_column($this->json()['boards'], 'title'));
        $cursor = $this->json()['nextCursor'];
        self::assertIsString($cursor);

        $this->call('GET', '/boards?limit=2&cursor=' . $cursor);
        self::assertResponseIsSuccessful();
        self::assertSame(["\u{9FA5}"], array_column($this->json()['boards'], 'title'));
        self::assertNull($this->json()['nextCursor']);
    }

    public function testABoardOfAnotherContextOrUnknownIsTheSame404(): void
    {
        $this->call('GET', '/boards/' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('Roadmap', $this->json()['board']['title']);
        self::assertSame(['To do', 'Doing', 'Done'], array_column($this->json()['board']['columns'], 'name'));

        $this->call('GET', '/boards/' . $this->acme->getId());
        $this->assertError(404, 'not_found');
        $this->call('GET', '/boards/unknown-board');
        $this->assertError(404, 'not_found');
    }

    public function testAColumnIsPagedInBoardOrderWithACursor(): void
    {
        $column = $this->roadmap->getColumns()->get(0);
        self::assertNotNull($column);
        foreach (['A', 'B', 'C'] as $position => $title) {
            $this->task($this->roadmap, $title, 0, null, $position);
        }
        $this->task($this->roadmap, 'Other column', 1);

        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $column->getId() . '&limit=2');
        self::assertSame(['A', 'B'], array_column($this->json()['tasks'], 'title'));
        $cursor = (string) $this->json()['nextCursor'];
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=' . $column->getId() . '&limit=2&cursor=' . $cursor);
        self::assertSame(['C'], array_column($this->json()['tasks'], 'title'));
        self::assertNull($this->json()['nextCursor']);

        // A cursor of another list is refused.
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?sort=due&cursor=' . $cursor);
        $this->assertError(400, 'invalid_cursor');

        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?column=unknown');
        $this->assertError(400, 'invalid_field');
        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks?sort=random');
        $this->assertError(400, 'invalid_field');
    }

    public function testTheListViewSortsAndFilters(): void
    {
        $late  = $this->task($this->roadmap, 'Late', 0, null, 0);
        $soon  = $this->task($this->roadmap, 'Soon', 1);
        $never = $this->task($this->roadmap, 'Never', 0, null, 1);
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $this->edit($late, static fn (Task $task): Task => $task->setDueAt($today->modify('-2 days'))->setPriority(TaskPriority::Urgent)->setTags(['bug']));
        $this->edit($soon, static fn (Task $task): Task => $task->setDueAt($today->modify('+2 days'))->setPriority(TaskPriority::Low));
        $this->edit($never, static fn (Task $task): Task => $task->setPriority(TaskPriority::High));
        $this->member($soon, $this->owner, TaskMemberRole::Assignee);
        $path = '/boards/' . $this->roadmap->getId() . '/tasks';

        foreach (['position' => ['Late', 'Never', 'Soon'], 'due' => ['Late', 'Soon', 'Never'], 'priority' => ['Late', 'Never', 'Soon'], 'number' => null] as $sort => $expected) {
            $this->call('GET', $path . '?sort=' . $sort . '&limit=1');
            self::assertResponseIsSuccessful();
            $titles = array_column($this->json()['tasks'], 'title');
            $cursor = $this->json()['nextCursor'];
            while ($cursor !== null) {
                $this->call('GET', $path . '?sort=' . $sort . '&limit=1&cursor=' . $cursor);
                $titles = [...$titles, ...array_column($this->json()['tasks'], 'title')];
                $cursor = $this->json()['nextCursor'];
            }
            self::assertCount(3, $titles, $sort);
            if ($expected !== null) {
                self::assertSame($expected, $titles, $sort);
            }
        }
        $this->call('GET', $path . '?sort=updated');
        self::assertCount(3, $this->json()['tasks']);

        $this->call('GET', $path . '?due=overdue');
        self::assertSame(['Late'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?due=week');
        self::assertSame(['Soon'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?due=today');
        self::assertSame([], $this->json()['tasks']);
        $this->call('GET', $path . '?due=none');
        self::assertSame(['Never'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?assignee=me');
        self::assertSame(['Soon'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?priority=urgent&priority=high');
        self::assertSame(['Late', 'Never'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?tag=bug');
        self::assertSame(['Late'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', $path . '?q=Soon');
        self::assertSame(['Soon'], array_column($this->json()['tasks'], 'title'));

        foreach (['due=yesterday', 'assignee=someone', 'priority=extreme', 'tag=', 'q=' . str_repeat('w', 129)] as $query) {
            $this->call('GET', $path . '?' . $query);
            $this->assertError(400, 'invalid_field');
        }
    }

    public function testATaskThePersonMayNotOpenIsInNoPage(): void
    {
        $this->task($this->roadmap, 'Shown');
        $secret = $this->task($this->roadmap, 'Secret');
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($secret): void {
            if ($event->getTaskId() === $secret->getId()) {
                $event->deny();
            }
        });

        $this->call('GET', '/boards/' . $this->roadmap->getId() . '/tasks');
        self::assertSame(['Shown'], array_column($this->json()['tasks'], 'title'));
        $this->call('GET', '/tasks/' . $secret->getId());
        $this->assertError(404, 'not_found');
    }

    public function testATaskDetailShowsWhatTheReaderMaySee(): void
    {
        $task  = $this->task($this->roadmap, 'Detail');
        $child = $this->task($this->roadmap, 'Child', 0, $task);
        $item  = $this->task($this->roadmap, 'Item', 2, $task);
        $this->edit($item, static fn (Task $task): Task => $task->setTags(['checklist:Launch'])->markCompleted());
        $other = $this->task($this->acme, 'Elsewhere');
        $this->edit($task, static fn (Task $task): Task => $task->setDescription("# Title\n\nSee #[guessed](issue:42) and #[x](issue:43) ![shot](beacon-file:f1) ![no](beacon-file:f2) ![web](https://example.com/a.png)\n\n- [x] done\n- [ ] todo\n\n---\n\n```\n#[t](issue:42)\n```"));
        $fresh = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);
        $this->em->persist(new TaskDependency($fresh, $this->em->find(Task::class, $other->getId()) ?? $fresh, TaskDependencyType::Blocks));
        $this->em->persist(TaskLink::forHostObject($fresh, 'issue', '42'));
        $this->em->persist(TaskLink::forHostObject($fresh, 'issue', '43'));
        $this->em->flush();
        ClientApiTestHost::$references   = ['issue:42' => 'Login breaks'];
        ClientApiTestHost::$visibleLinks = ['issue:42'];
        ClientApiTestHost::$files        = ['f1'];

        $this->call('GET', '/tasks/' . $task->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('ETag', '"' . $this->json()['task']['revision'] . '"');
        $detail = $this->json()['task'];
        self::assertSame(['total' => 1, 'done' => 0], $detail['subtaskSummary']);
        self::assertSame(['total' => 1, 'done' => 1], $detail['checklistSummary']);
        self::assertSame([['name' => 'Launch', 'items' => [['id' => $item->getId(), 'title' => 'Item', 'done' => true, 'revision' => $detail['checklists'][0]['items'][0]['revision']]]]], $detail['checklists']);
        self::assertSame([['type' => 'blocks', 'task' => ['visible' => false]]], $detail['dependencies']);
        self::assertEqualsCanonicalizing([['type' => 'host_object', 'objectType' => 'issue', 'url' => null, 'title' => 'issue #42', 'visible' => true], ['type' => 'host_object', 'visible' => false]], $detail['links']);
        $blocks = $detail['description']['blocks'];
        self::assertSame('tokens/1', $detail['description']['format']);
        self::assertSame(['type' => 'heading', 'level' => 1, 'tokens' => [['type' => 'text', 'text' => 'Title']]], $blocks[0]);
        $paragraph = $blocks[1]['tokens'];
        self::assertSame(['type' => 'reference', 'visible' => true, 'refType' => 'issue', 'id' => '42', 'title' => 'Login breaks', 'url' => '/issues/42'], $paragraph[1]);
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $paragraph[3]);
        self::assertSame(['type' => 'image', 'visible' => true, 'fileId' => 'f1', 'alt' => 'shot'], $paragraph[5]);
        self::assertSame(['type' => 'image', 'visible' => false], $paragraph[7]);
        self::assertSame(['type' => 'image', 'visible' => false], $paragraph[9]);
        self::assertSame([true, false], $blocks[2]['checked']);
        self::assertSame(['type' => 'rule'], $blocks[3]);
        self::assertSame(['type' => 'code', 'text' => '#[t](issue:42)'], $blocks[4]);
        self::assertNull($detail['parent']);
        self::assertTrue($detail['permissions']['canEdit']);

        $this->call('GET', '/tasks/' . $child->getId());
        self::assertSame(['id' => $task->getId(), 'number' => null, 'title' => 'Detail', 'visible' => true], $this->json()['task']['parent']);

        $this->call('GET', '/tasks/' . $task->getId() . '/subtasks');
        self::assertSame(['Child', 'Item'], array_column($this->json()['tasks'], 'title'));
    }

    public function testCommentsAreNewestFirstAsTokens(): void
    {
        $task = $this->task($this->roadmap, 'Talked about');
        $at   = new DateTimeImmutable('2026-01-01 10:00:00');
        foreach (['First **one**', 'Second', 'Third'] as $index => $body) {
            $this->em->persist(new TaskComment($this->em->find(Task::class, $task->getId()) ?? $task, $this->em->find(AppUser::class, $this->owner->getId()), $body, [], $at->modify('+' . $index . ' minutes')));
        }
        $this->em->flush();

        $this->call('GET', '/tasks/' . $task->getId() . '/comments?limit=2');
        self::assertResponseIsSuccessful();
        self::assertSame([[['type' => 'text', 'text' => 'Third']], [['type' => 'text', 'text' => 'Second']]], array_map(static fn (array $comment): array => $comment['body']['blocks'][0]['tokens'], $this->json()['comments']));
        self::assertSame('uuid-' . $this->owner->getId(), $this->json()['comments'][0]['author']['id']);
        $this->call('GET', '/tasks/' . $task->getId() . '/comments?before=' . $this->json()['nextCursor']);
        self::assertSame([['type' => 'text', 'text' => 'First '], ['type' => 'text', 'text' => 'one', 'marks' => ['bold']]], $this->json()['comments'][0]['body']['blocks'][0]['tokens']);
        self::assertNull($this->json()['nextCursor']);

        $this->call('GET', '/tasks/' . $task->getId() . '/comments?before=garbage');
        $this->assertError(400, 'invalid_cursor');
    }

    public function testMyTasksAreBucketedAndCounted(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $dates = ['Overdue' => '-3 days', 'Today' => '+3 hours', 'Upcoming' => '+5 days', 'Later' => '+30 days', 'Undated' => null];
        foreach ($dates as $title => $offset) {
            $task = $this->task($this->roadmap, $title);
            $this->edit($task, static fn (Task $task): Task => $task->setDueAt($offset === null ? null : $today->modify($offset)));
            $this->member($task, $this->owner, TaskMemberRole::Assignee);
        }
        $review = $this->task($this->roadmap, 'Review me');
        $this->member($review, $this->owner, TaskMemberRole::Reviewer);
        $done = $this->task($this->roadmap, 'Done already', 2);
        $this->member($done, $this->owner, TaskMemberRole::Assignee);
        $theirs = $this->task($this->roadmap, 'Theirs');
        $this->member($theirs, $this->user('colleague@example.com'), TaskMemberRole::Assignee);

        $this->call('GET', '/my-tasks');
        self::assertResponseIsSuccessful();
        self::assertSame(['Overdue', 'Today', 'Upcoming', 'Later', 'Undated'], array_column($this->json()['tasks'], 'title'));
        self::assertSame(['overdue' => 1, 'today' => 1, 'upcoming' => 1, 'review' => 1], $this->json()['counts']);

        foreach (['overdue' => ['Overdue'], 'today' => ['Today'], 'upcoming' => ['Upcoming'], 'no_date' => ['Undated'], 'review' => ['Review me']] as $bucket => $titles) {
            $this->call('GET', '/my-tasks?bucket=' . $bucket . '&today=' . $today->format('Y-m-d'));
            self::assertSame($titles, array_column($this->json()['tasks'], 'title'), $bucket);
        }
        $this->call('GET', '/my-tasks?limit=2');
        $this->call('GET', '/my-tasks?limit=2&cursor=' . $this->json()['nextCursor']);
        self::assertSame(['Upcoming', 'Later'], array_column($this->json()['tasks'], 'title'));
        self::assertArrayNotHasKey('counts', $this->json());

        $this->call('GET', '/my-tasks?bucket=someday');
        $this->assertError(400, 'invalid_field');
        foreach (['2020-01-01', '2026-13-45', 'today'] as $wrong) {
            $this->call('GET', '/my-tasks?today=' . $wrong);
            $this->assertError(400, 'invalid_date');
        }

        // Another context's tasks are not mine here.
        $this->call('GET', '/my-tasks', context: 'org:acme');
        self::assertSame([], $this->json()['tasks']);
    }

    public function testATaskIsFoundByItsNumberInABoard(): void
    {
        $this->call('GET', '/tasks/12?board=' . $this->roadmap->getId());
        $this->assertError(404, 'not_found');
        $this->call('GET', '/tasks/x1?board=' . $this->roadmap->getId());
        $this->assertError(404, 'not_found');
    }

    public function testPeopleAreTheAssignableMembersOfTheBoardsTeam(): void
    {
        $colleague                      = $this->user('colleague@example.com');
        ApiTestHost::$members['team-1'] = [
            new TaskApiMember('uuid-' . $colleague->getId(), 'Colleague Person', false, 'colleague@example.com'),
            new TaskApiMember('uuid-' . $this->owner->getId(), 'Owner Person', false, 'owner@example.com'),
            new TaskApiMember('uuid-999', 'Ghost', false),
            new TaskApiMember('uuid-' . $colleague->getId(), 'Bot', true),
        ];

        $this->call('GET', '/people?board=' . $this->roadmap->getId() . '&q=col');
        self::assertResponseIsSuccessful();
        self::assertSame([['id' => 'uuid-' . $colleague->getId(), 'name' => 'Colleague Person', 'initials' => 'CP', 'avatarUrl' => null]], $this->json()['people']);

        $this->call('GET', '/people');
        $this->assertError(400, 'invalid_field');
        $this->call('GET', '/people?board=' . $this->acme->getId());
        $this->assertError(404, 'not_found');
    }

    public function testChangesFollowTheCursorAndOnlyShowVisibleTasks(): void
    {
        $this->call('GET', '/changes');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['changes']);
        self::assertTrue($this->json()['reset']);
        $start = (string) $this->json()['nextCursor'];

        $task = $this->task($this->roadmap, 'Changed');
        $this->task($this->acme, 'Another context');
        $this->edit($task, static fn (Task $task) => (new ReflectionProperty(Task::class, 'updatedAt'))->setValue($task, new DateTimeImmutable('+1 minute')));
        $this->call('GET', '/changes?since=' . $start);
        self::assertSame([['taskId' => $task->getId(), 'boardId' => $this->roadmap->getId(), 'revision' => 1]], array_map(static fn (array $change): array => ['taskId' => $change['taskId'], 'boardId' => $change['boardId'], 'revision' => 1], $this->json()['changes']));
        self::assertFalse($this->json()['reset']);
        $next = (string) $this->json()['nextCursor'];
        $this->call('GET', '/changes?since=' . $next);
        self::assertSame([], $this->json()['changes']);

        // A cursor of another context is not one here.
        $this->call('GET', '/changes?since=' . $next, context: 'org:acme');
        $this->assertError(400, 'invalid_cursor');
    }

    public function testQuestionsAreNotFoundWhenQuestionsAreOff(): void
    {
        $task = $this->task($this->roadmap, 'Asked');
        $this->call('GET', '/tasks/' . $task->getId() . '/questions');
        $this->assertError(404, 'not_found');
        $this->call('GET', '/questions/waiting');
        $this->assertError(404, 'not_found');
    }

    public function testABoardThePersonMayNotViewIsNotListed(): void
    {
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, function (BoardAccessCheckEvent $event): void {
            if ($event->getBoardId() === $this->roadmap->getId()) {
                $event->deny();
            }
        });
        $this->call('GET', '/boards');
        self::assertSame([], $this->json()['boards']);
    }

    /**
     * @param callable(Task): mixed $change
     */
    private function edit(Task $task, callable $change): void
    {
        $fresh = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);
        $change($fresh);
        $this->em->flush();
    }

    private function member(Task $task, AppUser $user, TaskMemberRole $role): void
    {
        $fresh = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $fresh);
        $fresh->addMember(new TaskMember($fresh, $this->em->find(AppUser::class, $user->getId()) ?? $user, $role));
        $this->em->flush();
    }
}
