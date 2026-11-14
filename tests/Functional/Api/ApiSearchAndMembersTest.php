<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\Value\TaskApiMember;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_map;
use function array_unique;
use function range;
use function rawurlencode;
use function sort;
use function str_repeat;

/**
 * Spec 234 (TaskBoard 2.16.0): the text search of the task lists (`query`), within reach only, and the members of a
 * team with the name lookup.
 */
final class ApiSearchAndMembersTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        self::$hostConfig = ['search' => ['comments' => true, 'max_matches' => 3]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testQueryMatchesTitleAndDescriptionWithinReachOnly(): void
    {
        $this->task($this->roadmap, 'C-12 Payment gateway');
        $described = $this->task($this->roadmap, 'Another');
        $described->setDescription('Refactor the PAYMENT module');
        $this->task($this->roadmap, 'Unrelated');
        $hidden = $this->task($this->board('Secret', tenantKey: 'tenant-b'), 'Payment of tenant b');
        $this->em->flush();

        $this->get('/api/tasks/v2/team/team-1/task?query=payment');
        self::assertResponseIsSuccessful();
        $titles = array_column($this->listOf('tasks'), 'name');
        sort($titles);
        self::assertSame(['Another', 'C-12 Payment gateway'], $titles);
        self::assertNotContains($hidden->getTitle(), $titles);

        $this->get('/api/tasks/v2/team/team-1/task?query=' . rawurlencode('c-12 gateway'));
        self::assertSame(['C-12 Payment gateway'], array_column($this->listOf('tasks'), 'name'));

        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/task?query=' . rawurlencode('+payment -gateway "x" %'));
        self::assertSame([], $this->listOf('tasks'));

        $this->get('/api/tasks/v2/team/team-1/task?query=nothing-like-this');
        self::assertSame([], $this->listOf('tasks'));
        $this->get('/api/tasks/v2/team/team-1/task?query=' . str_repeat('x', 201));
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testCommentsMatchAndTooManyMatchesAre400(): void
    {
        $task = $this->task($this->roadmap, 'Invoices');
        $this->em->persist(new TaskComment($task, $this->owner, 'Waiting on the 50%_off promo'));
        $deleted = new TaskComment($this->task($this->roadmap, 'Other'), $this->owner, 'promo');
        $this->em->persist($deleted->markDeleted());
        $this->em->flush();
        $this->get('/api/tasks/v2/team/team-1/task?query=' . rawurlencode('50%_off'));
        self::assertSame([$task->getId()], $this->idsOf('tasks'));
        $this->get('/api/tasks/v2/team/team-1/task?query=promo');
        self::assertSame([$task->getId()], $this->idsOf('tasks'));

        // A request resets the manager: the fixtures are found again.
        $this->owner   = $this->em->find(AppUser::class, $this->owner->getId()) ?? $this->owner;
        $this->roadmap = $this->em->find(TaskBoard::class, $this->roadmap->getId()) ?? $this->roadmap;
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->task($this->roadmap, $title . ' widget');
        }
        $this->get('/api/tasks/v2/team/team-1/task?query=widget');
        self::assertCount(3, $this->listOf('tasks'));
        $this->owner   = $this->em->find(AppUser::class, $this->owner->getId()) ?? $this->owner;
        $this->roadmap = $this->em->find(TaskBoard::class, $this->roadmap->getId()) ?? $this->roadmap;
        $this->task($this->roadmap, 'Four widget');
        $this->get('/api/tasks/v2/team/team-1/task?query=widget');
        $this->assertRefused(400, 'SEARCH_001');
    }

    public function testLookupLimitsAndAViewerTheHostDoesNotKnow(): void
    {
        ApiTestHost::$members['team-1'] = array_map(static fn (int $i): TaskApiMember => new TaskApiMember('uuid-' . (100 + $i), 'Ana ' . $i, false, 'ana' . $i . '@example.com'), range(1, 25));
        $this->get('/api/tasks/v2/team/team-1/member?name=ana');
        self::assertCount(20, $this->listOf('members'));
        $this->get('/api/tasks/v2/team/team-1/member?name=' . str_repeat('a', 129));
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/team/team-1/member?name[]=ana');
        $this->assertRefused(400, 'INPUT_002');
        ApiTestHost::$unknownEmails = ['owner@example.com'];
        $this->get('/api/tasks/v2/team');
        self::assertSame([null], array_unique(array_column(array_column($this->json()['teams'][0]['members'], 'user'), 'email')));
    }

    public function testMyTasksTokensSearchOnlyTheirOwnTasks(): void
    {
        $colleague = $this->user('colleague@example.com');
        $mine      = $this->task($this->roadmap, 'Payment mine');
        $theirs    = $this->task($this->roadmap, 'Payment theirs');
        $theirs->reassignCreator($colleague);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->get('/api/tasks/v2/team/team-1/task?query=payment');
        self::assertSame([$mine->getId()], $this->idsOf('tasks'));
    }

    public function testTeamsListTheirMembersWithTheEmailOnlyForTheViewer(): void
    {
        $self                           = 'uuid-' . $this->owner->getId();
        ApiTestHost::$members['team-1'] = [
            new TaskApiMember($self, 'Héctor Franco', false, 'owner@example.com'),
            new TaskApiMember('uuid-99', 'ai-task-verifier', true, 'agent@example.com'),
        ];
        $this->get('/api/tasks/v2/team');
        self::assertResponseIsSuccessful();
        $members = $this->json()['teams'][0]['members'];
        self::assertSame([$self, 'uuid-99'], array_column(array_column($members, 'user'), 'id'));
        self::assertSame(['owner@example.com', null], array_column(array_column($members, 'user'), 'email'));
        self::assertSame([false, true], array_column(array_column($members, 'user'), 'service_account'));
        self::assertSame([], $this->json()['teams'][1]['members']);
    }

    public function testTheNameLookupIsAPrefixWithoutCaseOrAccentsAndReturnsNoEmail(): void
    {
        ApiTestHost::$members['team-1'] = [
            new TaskApiMember('uuid-71', 'José Álvarez', false, 'jose@example.com'),
            new TaskApiMember('uuid-72', 'Joséphine Roy'),
            new TaskApiMember('uuid-73', 'ai-story-decomposer', true),
        ];
        $this->get('/api/tasks/v2/team/team-1/member?name=alva');
        self::assertSame([['id' => 'uuid-71', 'username' => 'José Álvarez', 'service_account' => false]], $this->listOf('members'));
        $this->get('/api/tasks/v2/team/team-1/member?name=JOSE');
        self::assertSame(['uuid-71', 'uuid-72'], $this->idsOf('members'));
        $this->get('/api/tasks/v2/team/team-1/member?name=story');
        self::assertSame(['uuid-73'], $this->idsOf('members'));
        $this->get('/api/tasks/v2/team/team-1/member?name=');
        self::assertSame([], $this->listOf('members'));
        $this->get('/api/tasks/v2/team/team-1/member');
        self::assertCount(3, $this->listOf('members'));
        self::assertNull($this->listOf('members')[0]['email']);
    }

    public function testAnotherTeamIs404BeforeTheHostIsAsked(): void
    {
        ApiTestHost::$members['team-9'] = [new TaskApiMember('uuid-1', 'Leak')];
        $this->get('/api/tasks/v2/team/team-9/member?name=lea');
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/team/team-9/member');
        $this->assertRefused(404, 'ACCESS_001');
        self::assertSame([], ApiTestHost::$membersAsked);
    }

    public function testDependentsAreTheTasksBlockedByATaskOfItsTenant(): void
    {
        $blocker = $this->task($this->roadmap, 'Blocker');
        $waits   = $this->task($this->roadmap, 'Waits');
        $blocked = $this->task($this->roadmap, 'Blocked through blocks');
        $related = $this->task($this->roadmap, 'Related');
        $this->send('POST', '/api/tasks/v2/task/' . $waits->getId() . '/dependency', ['depends_on' => $blocker->getId()]);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/tasks/v2/task/' . $blocked->getId() . '/dependency', ['depends_on' => $blocker->getId()]);
        $this->send('POST', '/api/tasks/v2/task/' . $blocker->getId() . '/link/' . $related->getId());
        $manager = self::getContainer()->get(TaskDependencyManager::class);
        self::assertInstanceOf(TaskDependencyManager::class, $manager);
        $ids = array_map(static fn (Task $task): string => $task->getId(), $manager->dependentsOf($blocker));
        sort($ids);
        $expected = [$waits->getId(), $blocked->getId()];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame([], $manager->dependentsOf($waits));
    }
}
