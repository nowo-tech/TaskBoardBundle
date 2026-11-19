<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search\SearchKit;

use LogicException;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\TaskBoardBundle\Bridge\SearchKit\BoardSearchDefinition;
use Nowo\TaskBoardBundle\Bridge\SearchKit\TaskBoardSearchDefinition;
use Nowo\TaskBoardBundle\Bridge\SearchKit\TaskSearchDefinition;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Nowo\TaskBoardBundle\Tests\Functional\Search\HeaderSearchTestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function class_exists;

/**
 * Since 2.25.0: the bundle's own rules of its SearchKit groups, beyond SearchKit's contract — board visibility and
 * TASK_ACCESS_CHECK through the generic provider, the scopes, the tenant SearchKit sees, the rows and recents.
 */
final class TaskBoardSearchDefinitionTest extends HeaderSearchTestCase
{
    protected function setUp(): void
    {
        if (!class_exists(EntitySearchDefinition::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
        }
        parent::setUp();
    }

    public function testTasksThroughTheProviderFollowBoardVisibilityAndTaskAccessCheck(): void
    {
        $bob    = $this->user('bob@example.com');
        $board  = $this->board('Mine');
        $hidden = $this->described($board, 'Report hidden', null);
        $this->described($board, 'Report shown', 'With **details** here');
        $this->described($this->board('Bob', creator: $bob), 'Report of bob', null);
        $this->listen(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($hidden): void {
            if ($event->getTaskId() === $hidden->getId()) {
                $event->deny();
            }
        });
        $this->signIn($this->owner);

        $group = $this->provider($this->tasksDefinition())->search($this->query('report'));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['Report shown'], array_map(static fn ($item): string => $item->title, $group->items));
        self::assertSame(1, $group->total);
        self::assertSame('Mine — With details here', $group->items[0]->subtitle);
        self::assertStringStartsWith('/tools/task-board/task/', $group->items[0]->url);
        self::assertNull($this->provider($this->tasksDefinition())->search($this->query('report hidden')), 'a denied task is a missing one');
    }

    public function testBoardsThroughTheProvider(): void
    {
        $bob = $this->user('bob@example.com');
        $this->board('Launch plan');
        $this->board('Launch secret', creator: $bob);
        $this->signIn($this->owner);

        $group = $this->provider($this->boardsDefinition())->search($this->query('launch'));

        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['Launch plan'], array_map(static fn ($item): string => $item->title, $group->items));
        self::assertSame(1, $group->total);
        self::assertStringStartsWith('/tools/task-board/board/', $group->items[0]->url);
    }

    public function testScopes(): void
    {
        $first  = $this->board('First');
        $second = $this->board('Second');
        $this->described($first, 'Scoped', null);
        $this->described($second, 'Scoped', null);
        $this->signIn($this->owner);
        $provider = $this->provider($this->tasksDefinition());

        $group = $provider->search($this->query('scoped', 'board:' . $first->getId()));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
        self::assertNull($provider->search($this->query('scoped', 'project:7')));

        $this->listen(TaskBoardEvents::SEARCH_SCOPE, static function (SearchScopeEvent $event) use ($second): void {
            if ($event->getScope() === 'project:7') {
                $event->onlyBoards([$second->getId()]);
                $event->acceptScope();
            }
        });
        $group = $provider->search($this->query('scoped', 'project:7'));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
    }

    public function testNoViewerNoTenantNoFeature(): void
    {
        $this->described($this->board(), 'Anything', null);
        $definition = $this->tasksDefinition();

        self::assertNull($definition->tenant($this->query('anything')), 'not signed in');
        $this->signIn($this->user('other@example.com'));
        self::assertNull($definition->tenant($this->query('anything')), 'another identifier');
        self::assertSame([], $definition->filterVisible([new stdClass()], '*', $this->query('anything')));
        self::assertSame([], $this->boardsDefinition()->filterVisible([new stdClass()], '*', $this->query('anything')));

        $this->signIn($this->owner);
        self::assertSame(TaskBoardSearchDefinition::ANY_TENANT, $definition->tenant($this->query('anything')));
        self::assertTrue($definition->featureEnabled('*', $this->query('anything')));
        $this->signIn($this->owner, []);
        self::assertFalse($definition->featureEnabled('*', $this->query('anything')));
        self::assertNull($this->provider($definition)->search($this->query('anything')));
    }

    public function testWithTenancyTheTenantIsThePagesAndAnUnknownPageHasNone(): void
    {
        $this->boot(self::TENANCY);
        MemberTenantResolver::$members = ['acme' => ['owner@example.com']];
        $this->described($this->board('Acme board', tenantKey: 'acme'), 'Tenant task', null);
        $this->signIn($this->owner);
        $definition = $this->tasksDefinition();
        $stack      = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);

        self::assertSame(TaskBoardSearchDefinition::ANY_TENANT, $definition->tenant($this->query('tenant')));
        $stack->push(new Request(attributes: ['tenantSlug' => 'acme']));
        self::assertSame('acme', $definition->tenant($this->query('tenant')));
        $group = $this->provider($definition)->search($this->query('tenant'));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertStringStartsWith('/t/acme/', $group->items[0]->url);
        self::assertSame('Acme board · acme', $group->items[0]->subtitle);
        $boards = $this->provider($this->boardsDefinition())->search($this->query('acme'));
        self::assertInstanceOf(SearchGroup::class, $boards);
        self::assertSame('acme', $boards->items[0]->subtitle);
        $stack->pop();
        $stack->push(new Request(attributes: ['tenantSlug' => 'globex']));
        self::assertNull($definition->tenant($this->query('tenant')));
        $stack->pop();

        self::assertSame('t1', $definition->referenceId(new SearchReference('/t/acme/task/t1', 'tasks', 't_nowo_task_board_task', ['taskId' => 't1'])));
    }

    public function testDescribesItsGroupsAndRecents(): void
    {
        $tasks  = $this->tasksDefinition();
        $boards = $this->boardsDefinition();
        $query  = $this->query('x');

        self::assertSame(['tasks', 'Tasks', 'tabler:checkbox', 'task_board.task', ['t.title', 't.description'], 't.id'], [$tasks->group(), $tasks->label($query), $tasks->icon(), $tasks->subjectType(), $tasks->searchableFields(), $tasks->idField()]);
        self::assertSame(['boards', 'Boards', 'tabler:layout-kanban', 'task_board.board', ['b.name'], 'b.id'], [$boards->group(), $boards->label($query), $boards->icon(), $boards->subjectType(), $boards->searchableFields(), $boards->idField()]);
        self::assertNull($tasks->seeAllUrl($query));
        self::assertNull($boards->seeAllUrl($query));
        self::assertSame(100, $tasks->maxCandidates());
        self::assertTrue($tasks->applyScope($tasks->baseQuery('*', $query), 'board:1', '*', $query));

        self::assertSame('t1', $tasks->referenceId(new SearchReference('/tools/task-board/task/t1', 'tasks', 'nowo_task_board_task', ['taskId' => 't1'])));
        self::assertNull($tasks->referenceId(new SearchReference('/x', 'tasks', 'other_route', ['taskId' => 't1'])));
        self::assertNull($tasks->referenceId(new SearchReference('/x', 'tasks', 'nowo_task_board_task', ['taskId' => ''])));
        self::assertSame('b1', $boards->referenceId(new SearchReference('/tools/task-board/board/b1', 'boards', 'nowo_task_board_board', ['boardId' => 'b1'])));
    }

    public function testRecentsAreResolvedThroughEveryCheck(): void
    {
        $task = $this->described($this->board(), 'Recent task', null);
        $this->signIn($this->owner);
        $provider = $this->provider($this->tasksDefinition());
        $url      = '/tools/task-board/task/' . $task->getId();

        $item = $provider->resolve(new SearchReference($url, 'tasks', 'nowo_task_board_task', ['taskId' => $task->getId()]), 'owner@example.com', null);
        self::assertNotNull($item);
        self::assertSame('Recent task', $item->title);
        self::assertNull($provider->resolve(new SearchReference($url, 'tasks', 'nowo_task_board_task', ['taskId' => $task->getId()]), 'bob@example.com', null));
    }

    public function testToItemRefusesAnotherRow(): void
    {
        $this->signIn($this->owner);

        $this->expectException(LogicException::class);
        $this->tasksDefinition()->toItem(new stdClass(), $this->query('x'));
    }

    public function testToItemOfABoardRefusesAnotherRow(): void
    {
        $this->signIn($this->owner);

        $this->expectException(LogicException::class);
        $this->boardsDefinition()->toItem(new stdClass(), $this->query('x'));
    }

    public function testTheGroupsAreRegisteredOnlyWhenEnabled(): void
    {
        self::assertInstanceOf(TaskSearchDefinition::class, self::getContainer()->get(TaskSearchDefinition::class));
        self::assertInstanceOf(BoardSearchDefinition::class, self::getContainer()->get(BoardSearchDefinition::class));

        $this->boot(['search_kit' => ['boards' => false, 'max_candidates' => 1000, 'priority' => 7]]);
        self::assertFalse(self::getContainer()->has(BoardSearchDefinition::class));
        $tasks = self::getContainer()->get(TaskSearchDefinition::class);
        self::assertInstanceOf(TaskSearchDefinition::class, $tasks);
        self::assertSame(1000, $tasks->maxCandidates());

        $this->boot(['search_kit' => ['enabled' => false]]);
        self::assertFalse(self::getContainer()->has(TaskSearchDefinition::class));
    }

    private function tasksDefinition(): TaskSearchDefinition
    {
        $definition = self::getContainer()->get(TaskSearchDefinition::class);
        self::assertInstanceOf(TaskSearchDefinition::class, $definition);

        return $definition;
    }

    private function boardsDefinition(): BoardSearchDefinition
    {
        $definition = self::getContainer()->get(BoardSearchDefinition::class);
        self::assertInstanceOf(BoardSearchDefinition::class, $definition);

        return $definition;
    }

    private function provider(EntitySearchDefinition $definition): EntitySearchProvider
    {
        return new EntitySearchProvider($definition);
    }

    private function query(string $text, ?string $scope = null): SearchQuery
    {
        return SearchQuery::forUserIdentifier('owner@example.com', $text, 10, $scope);
    }

    /**
     * @param list<string>|null $roles
     */
    private function signIn(AppUser $user, ?array $roles = null): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $roles ?? $user->getRoles()));
    }
}
