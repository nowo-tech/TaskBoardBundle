<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\ColumnSets;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\ColumnSetAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\ColumnSetQueryEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\ColumnSetRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmColumnSetRepository;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function strlen;

/**
 * 1.11 (spec 171 block E): column icon, column delete, column sets and their hooks.
 */
final class ColumnSetsTest extends TestCase
{
    private TestUser $user;

    private TaskBoard $board;

    protected function setUp(): void
    {
        $this->user  = new TestUser('1', 'owner@example.com');
        $this->board = new TaskBoard('Roadmap', 'roadmap', $this->user);
        $this->board->addColumn(new BoardColumn($this->board, 'To do', 0, '#94a3b8', 'tabler:inbox'));
        $this->board->addColumn(new BoardColumn($this->board, 'Done', 1));
    }

    public function testColumnIconIsAddedUpdatedAndCleared(): void
    {
        $manager = new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class));
        $column  = $manager->add($this->board, new BoardColumnFormData('Review', ' ', ' tabler:eye '));

        self::assertSame('tabler:eye', $column->getIcon());
        self::assertNull($column->getColor());

        $manager->update($column, new BoardColumnFormData('Review', '#111111', ''));
        self::assertNull($column->getIcon());
        self::assertSame('#111111', $column->getColor());
    }

    public function testDeletingAColumnMovesItsTasksToTheFirstColumn(): void
    {
        [$todo, $done] = $this->board->getColumns()->toArray();
        $review        = new BoardColumn($this->board, 'Review', 2);
        $this->board->addColumn($review);
        $existing = $this->task('Existing', $todo, 4);
        $moved    = $this->task('Moved', $review, 0);
        $finished = $this->task('Finished', $done, 0);
        $finished->markCompleted();

        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('saveAll');
        $manager = new BoardColumnManager($repository);

        // Deleting the done column reopens its tasks at the end of the first column.
        self::assertTrue($manager->delete($done));
        self::assertSame($todo, $finished->getColumn());
        self::assertSame(5, $finished->getPosition());
        self::assertFalse($finished->isCompleted());
        self::assertSame($todo, $existing->getColumn());
        self::assertSame(1, $review->getPosition());
        self::assertFalse($this->board->getColumns()->contains($done));

        self::assertTrue($manager->delete($review));
        self::assertSame($todo, $moved->getColumn());
        self::assertSame(6, $moved->getPosition());
        self::assertSame(0, $todo->getPosition());
    }

    public function testTasksMovedIntoTheLastRemainingColumnAreCompleted(): void
    {
        [$todo, $done] = $this->board->getColumns()->toArray();
        $task          = $this->task('Open', $todo, 0);

        $manager = new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class));
        self::assertTrue($manager->delete($todo));

        self::assertSame($done, $task->getColumn());
        self::assertTrue($task->isCompleted());
        self::assertSame(0, $done->getPosition());

        // The last column of a board is kept.
        self::assertFalse($manager->delete($done));
        self::assertTrue($this->board->getColumns()->contains($done));
    }

    public function testColumnSetCopiesTheBoardColumns(): void
    {
        $set = ColumnSet::fromBoard($this->board, 'Kanban', $this->user, 'project-1');

        self::assertSame('Kanban', $set->getName());
        self::assertSame($this->user, $set->getCreatedBy());
        self::assertSame('project-1', $set->getScopeKey());
        self::assertSame(36, strlen($set->getId()));
        self::assertNotNull($set->getCreatedAt());
        self::assertSame([
            ['name' => 'To do', 'color' => '#94a3b8', 'icon' => 'tabler:inbox'],
            ['name' => 'Done', 'color' => null, 'icon' => null],
        ], $set->getItems());
        self::assertSame('Flow', $set->setName('Flow')->getName());
    }

    public function testColumnSetItemsToleratePartialJson(): void
    {
        $set = new ColumnSet('Legacy');
        $set->addItem('A', '#fff', 'tabler:x');
        (new ReflectionProperty(ColumnSet::class, 'items'))->setValue($set, [['name' => 'A', 'color' => 1], ['name' => 'B']]);

        self::assertSame([
            ['name' => 'A', 'color' => null, 'icon' => null],
            ['name' => 'B', 'color' => null, 'icon' => null],
        ], $set->getItems());
        self::assertNull($set->getCreatedBy());
        self::assertNull($set->getScopeKey());
    }

    public function testDefaultScopeIsTheTenantOfTheBoard(): void
    {
        $this->board->setTenantKey('project-1');
        $repository = $this->createMock(ColumnSetRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findByScope')->willReturnCallback(static fn (?string $scope): array => $scope === 'project-1' ? [new ColumnSet('A')] : []);

        $manager = new ColumnSetManager($repository, $this->createMock(BoardColumnRepositoryInterface::class));

        self::assertCount(1, $manager->available($this->user, $this->board));
        self::assertSame([], $manager->available($this->user, null, 'project-2'));
    }

    public function testListenersScopeAndOverrideTheSets(): void
    {
        $shared     = new ColumnSet('Shared');
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::COLUMN_SET_QUERY, static function (ColumnSetQueryEvent $event): void {
            $event->setScopeKey(null);
        });

        $repository = $this->createMock(ColumnSetRepositoryInterface::class);
        $repository->method('findByScope')->willReturnCallback(static fn (?string $scope): array => $scope === null ? [$shared] : []);
        $repository->expects(self::once())->method('save')->with(self::callback(static fn (ColumnSet $set): bool => $set->getScopeKey() === null && $set->getName() === 'Mine'));
        $repository->expects(self::once())->method('remove')->with($shared);

        $this->board->setTenantKey('project-1');
        $manager = new ColumnSetManager($repository, $this->createMock(BoardColumnRepositoryInterface::class), $dispatcher);

        self::assertSame([$shared], $manager->available($this->user, $this->board));
        self::assertSame($shared, $manager->find($shared->getId(), $this->user, $this->board));
        self::assertNull($manager->find('missing', $this->user, $this->board));
        self::assertCount(2, $manager->saveFromBoard($this->board, ' Mine ', $this->user)->getItems());
        $manager->delete($shared);

        $override = new ColumnSet('Override');
        $dispatcher->addListener(TaskBoardEvents::COLUMN_SET_QUERY, static function (ColumnSetQueryEvent $event) use ($override): void {
            $event->overrideList([$override]);
        });
        self::assertSame([$override], $manager->available($this->user, $this->board));
    }

    public function testApplyingASetAppendsCopiesOfItsColumns(): void
    {
        $set = (new ColumnSet('Bugs'))->addItem('Triage', '#dc2626', 'tabler:bug')->addItem('Fixed');

        $columns = $this->createMock(BoardColumnRepositoryInterface::class);
        $columns->expects(self::once())->method('saveAll');
        $manager = new ColumnSetManager($this->createMock(ColumnSetRepositoryInterface::class), $columns);

        $added = $manager->applyToBoard($set, $this->board);

        self::assertCount(2, $added);
        self::assertSame(['Triage', 2, '#dc2626', 'tabler:bug'], [$added[0]->getName(), $added[0]->getPosition(), $added[0]->getColor(), $added[0]->getIcon()]);
        self::assertSame(3, $added[1]->getPosition());
        self::assertCount(4, $this->board->getColumns());

        self::assertSame([], $manager->applyToBoard(new ColumnSet('Empty'), $this->board));
    }

    public function testNewBoardTakesTheColumnsOfASet(): void
    {
        $boards = $this->createMock(TaskBoardRepositoryInterface::class);
        $boards->method('findBySlug')->willReturn(null);
        $creator = new TaskBoardCreator($boards, [['name' => 'Default', 'color' => null]]);

        $set   = (new ColumnSet('Bugs'))->addItem('Triage', '#dc2626', 'tabler:bug')->addItem('Fixed');
        $board = $creator->create(new TaskBoardFormData(name: 'Bugs'), $this->user, 'project-1', $set);
        self::assertSame(['Triage', 'Fixed'], array_map(static fn (BoardColumn $column): string => $column->getName(), $board->getColumns()->toArray()));
        self::assertSame('tabler:bug', $board->getColumns()->first()->getIcon());

        $fallback = $creator->create(new TaskBoardFormData(name: 'Other'), $this->user, null, new ColumnSet('Empty'));
        self::assertSame('Default', $fallback->getColumns()->first()->getName());
        self::assertNull($fallback->getColumns()->first()->getIcon());
    }

    public function testDeletingASetIsForItsCreatorAndManageRoles(): void
    {
        $other = new TestUser('2', 'other@example.com');
        $set   = new ColumnSet('Mine', $this->user);
        $auth  = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_ADMIN');

        $teams = $this->createMock(TeamMemberRepositoryInterface::class);
        self::assertTrue((new TaskBoardResourceAccessChecker($teams, new NullTaskBoardTeamMembershipResolver()))->canDeleteColumnSet(new TestUser('1', 'copy@example.com'), $set));
        self::assertFalse((new TaskBoardResourceAccessChecker($teams, new NullTaskBoardTeamMembershipResolver()))->canDeleteColumnSet($other, $set));
        self::assertFalse((new TaskBoardResourceAccessChecker($teams, new NullTaskBoardTeamMembershipResolver()))->canDeleteColumnSet($other, new ColumnSet('Orphan')));
        self::assertTrue((new TaskBoardResourceAccessChecker($teams, new NullTaskBoardTeamMembershipResolver(), $auth))->canDeleteColumnSet($other, $set));

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::COLUMN_SET_ACCESS_CHECK, static function (ColumnSetAccessCheckEvent $event): void {
            $event->getColumnSet()->getName() === 'Mine' ? $event->deny() : $event->grant();
        });
        $checker = new TaskBoardResourceAccessChecker($teams, new NullTaskBoardTeamMembershipResolver(), null, $dispatcher);
        self::assertFalse($checker->canDeleteColumnSet($this->user, $set));
        self::assertTrue($checker->canDeleteColumnSet($other, new ColumnSet('Theirs')));
    }

    public function testEventsExposeTheirContext(): void
    {
        $set   = new ColumnSet('A');
        $query = new ColumnSetQueryEvent($this->user, null, 'tenant-1');
        self::assertSame($this->user, $query->getSubject());
        self::assertNull($query->getBoard());
        self::assertSame('tenant-1', $query->getTenantKey());
        self::assertSame('tenant-1', $query->getScopeKey());
        self::assertNull($query->getOverrideList());

        $board = new ColumnSetQueryEvent($this->user, $this->board);
        self::assertSame($this->board, $board->getBoard());
        self::assertNull($board->getScopeKey());

        $access = new ColumnSetAccessCheckEvent($this->user, $set, true);
        self::assertSame($this->user, $access->getSubject());
        self::assertSame($set, $access->getColumnSet());
        self::assertTrue($access->isDefaultGranted());
        self::assertTrue($access->isGranted());

        $list = new BoardListQueryEvent($this->user, 'tenant-1');
        self::assertSame('tenant-1', $list->getTenantKey());
        self::assertNull((new BoardListQueryEvent($this->user))->getTenantKey());
    }

    public function testFilterBoardsPassesTheTenantToListeners(): void
    {
        $seen       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_LIST_QUERY, static function (BoardListQueryEvent $event) use (&$seen): void {
            $seen[] = $event->getTenantKey();
        });
        $checker = new TaskBoardResourceAccessChecker($this->createMock(TeamMemberRepositoryInterface::class), new NullTaskBoardTeamMembershipResolver(), null, $dispatcher);

        self::assertSame([$this->board], $checker->filterBoards($this->user, [$this->board], 'user-1'));
        self::assertSame(['user-1'], $seen);
    }

    public function testDtosKeepTheirValues(): void
    {
        self::assertSame('Kanban', (new ColumnSetFormData('Kanban'))->name);
        self::assertSame('set-1', (new ColumnSetApplyFormData('set-1'))->columnSetId);
        self::assertSame('set-1', (new TaskBoardFormData(columnSetId: 'set-1'))->columnSetId);
        self::assertSame('tabler:bug', (new BoardColumnFormData(icon: 'tabler:bug'))->icon);
    }

    public function testColumnIconsConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [['user_class' => User::class]]);
        self::assertSame(Configuration::DEFAULT_COLUMN_ICONS, $config['board']['column_icons']);
        self::assertCount(30, Configuration::DEFAULT_COLUMN_ICONS);
        self::assertSame('@NowoTaskBoardBundle/manage/task_panel.html.twig', $config['templates']['task_panel']);
        self::assertSame('/tools/task-board/task/{taskId}/panel', $config['routes']['task_panel']['path']);

        $custom = $processor->processConfiguration(new Configuration(), [['user_class' => User::class, 'board' => [
            'column_icons'    => ['lucide:bug'],
            'default_columns' => [['name' => 'Bugs', 'icon' => 'tabler:bug']],
        ]]]);
        self::assertSame(['lucide:bug'], $custom['board']['column_icons']);
        self::assertSame([['name' => 'Bugs', 'icon' => 'tabler:bug', 'color' => null]], $custom['board']['default_columns']);

        $this->expectException(InvalidConfigurationException::class);
        $processor->processConfiguration(new Configuration(), [['user_class' => User::class, 'board' => ['column_icons' => ['Bug Icon']]]]);
    }

    public function testExtensionWiresColumnSets(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'board' => ['column_icons' => ['tabler:bug']]]], $container);

        self::assertSame(['tabler:bug'], $container->getParameter('nowo_task_board.board.column_icons'));
        self::assertSame(DoctrineOrmColumnSetRepository::class, (string) $container->getAlias(ColumnSetRepositoryInterface::class));
        self::assertArrayHasKey('$managerRegistry', $container->getDefinition(DoctrineOrmColumnSetRepository::class)->getArguments());
        self::assertTrue($container->hasDefinition(ColumnSetManager::class));
    }

    public function testRoutesOfTheColumnSetsAndThePanelAreLoadedAndCopiedPerTenant(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes     = $config['routes'];
        $collection = (new TaskBoardRouteLoader($routes, '', [
            'enabled'      => true,
            'tenant_paths' => [['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_']],
        ]))->load('.', 'nowo_task_board');

        $expected = [
            'nowo_task_board_column_delete'     => ['/tools/task-board/board/{boardId}/column/{columnId}/delete', 'POST', 'deleteColumn'],
            'nowo_task_board_column_set_create' => ['/tools/task-board/board/{boardId}/column-sets', 'POST', 'createColumnSet'],
            'nowo_task_board_column_set_apply'  => ['/tools/task-board/board/{boardId}/column-sets/apply', 'POST', 'applyColumnSet'],
            'nowo_task_board_column_set_delete' => ['/tools/task-board/board/{boardId}/column-sets/{setId}/delete', 'POST', 'deleteColumnSet'],
            'nowo_task_board_task_panel'        => ['/tools/task-board/task/{taskId}/panel', 'GET', 'taskPanel'],
        ];
        foreach ($expected as $name => [$path, $method, $action]) {
            $route = $collection->get($name);
            self::assertNotNull($route, $name);
            self::assertSame($path, $route->getPath());
            self::assertSame([$method], $route->getMethods());
            self::assertStringEndsWith('::' . $action, (string) $route->getDefault('_controller'));
            self::assertNotNull($collection->get('app_project_' . $name), $name);
        }

        self::assertSame('/projects/{tenantSlug}/tasks/task/{taskId}/panel', $collection->get('app_project_nowo_task_board_task_panel')?->getPath());
    }

    private function task(string $title, BoardColumn $column, int $position): Task
    {
        $task = new Task($this->board, $title, $this->user, column: $column);
        $task->setPosition($position);
        $this->board->getTasks()->add($task);

        return $task;
    }
}
