<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Routing;

use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use PHPUnit\Framework\TestCase;

final class TaskBoardRouteLoaderTest extends TestCase
{
    public function testSupportsNowoTaskBoardType(): void
    {
        $loader = new TaskBoardRouteLoader([], '');
        self::assertTrue($loader->supports('.', 'nowo_task_board'));
        self::assertFalse($loader->supports('.', 'annotation'));
    }

    public function testLoadsConfiguredRoutes(): void
    {
        $loader = new TaskBoardRouteLoader(
            [
                'index' => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index'],
                'task'  => ['path' => '/tools/task-board/task/{taskId}', 'name' => 'nowo_task_board_task'],
            ],
            '',
        );

        $collection = $loader->load('.');
        self::assertNotNull($collection->get('nowo_task_board_index'));
        self::assertNotNull($collection->get('nowo_task_board_task'));
    }

    public function testSkipsMissingRouteKeys(): void
    {
        $loader = new TaskBoardRouteLoader(
            ['index' => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index']],
            '/prefix',
        );

        $collection = $loader->load('.');
        self::assertNotNull($collection->get('nowo_task_board_index'));
        self::assertNull($collection->get('nowo_task_board_board'));
    }

    public function testCanLoadRoutesAgainInTheSameProcess(): void
    {
        $loader = new TaskBoardRouteLoader(
            ['index' => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index']],
            '',
        );
        $first = $loader->load('.');

        $second = $loader->load('.');

        self::assertNotSame($first, $second);
        self::assertSame('/tools/task-board', $second->get('nowo_task_board_index')?->getPath());
    }

    public function testTenancyCopiesEveryRouteUnderEachTenantPath(): void
    {
        $loader = new TaskBoardRouteLoader(
            [
                'index'  => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index'],
                'board'  => ['path' => '/tools/task-board/board/{boardId}', 'name' => 'nowo_task_board_board'],
                'gantt'  => ['path' => '/gantt/{boardId}', 'name' => 'nowo_task_board_gantt'],
                'orphan' => ['path' => '/tools/task-board/orphan', 'name' => 'nowo_task_board_orphan'],
            ],
            '/prefix',
            [
                'enabled'      => true,
                'tenant_paths' => [
                    ['path' => '/projects/{tenantSlug}/tasks/', 'name_prefix' => 'app_project_', 'slug_prefix' => '', 'slug_requirement' => '\d+'],
                    ['path' => '/me/{tenantSlug}/tasks', 'name_prefix' => 'me_', 'slug_prefix' => 'user-'],
                ],
            ],
        );

        $collection = $loader->load('.');

        self::assertSame('/prefix/tools/task-board', $collection->get('nowo_task_board_index')?->getPath());
        self::assertSame('/projects/{tenantSlug}/tasks', $collection->get('app_project_nowo_task_board_index')?->getPath());
        self::assertSame('/projects/{tenantSlug}/tasks/board/{boardId}', $collection->get('app_project_nowo_task_board_board')?->getPath());
        self::assertSame('\d+', $collection->get('app_project_nowo_task_board_board')?->getRequirement('tenantSlug'));
        self::assertSame(['GET'], $collection->get('app_project_nowo_task_board_board')?->getMethods());
        // A route outside routes.index.path keeps its path under the tenant path.
        self::assertSame('/projects/{tenantSlug}/tasks/gantt/{boardId}', $collection->get('app_project_nowo_task_board_gantt')?->getPath());
        self::assertSame('/me/{tenantSlug}/tasks/board/{boardId}', $collection->get('me_nowo_task_board_board')?->getPath());
        self::assertSame('user\-[A-Za-z0-9_-]+', $collection->get('me_nowo_task_board_board')?->getRequirement('tenantSlug'));
        // Keys the loader does not know (no action) have no original and no copy.
        self::assertNull($collection->get('app_project_nowo_task_board_orphan'));
        self::assertNull($collection->get('nowo_task_board_board')?->getRequirement('tenantSlug'));
    }

    public function testTenantPathsAreIgnoredWithoutTenancy(): void
    {
        $loader = new TaskBoardRouteLoader(
            ['index' => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index']],
            '',
            ['enabled' => false, 'tenant_paths' => [['path' => '/p/{tenantSlug}', 'name_prefix' => 'app_']]],
        );

        self::assertNull($loader->load('.')->get('app_nowo_task_board_index'));
    }

    public function testTenantCopiesWithTheDefaultBasePath(): void
    {
        $loader = new TaskBoardRouteLoader(
            ['task' => ['path' => '/tools/task-board/task/{taskId}', 'name' => 'nowo_task_board_task']],
            '',
            ['enabled' => true, 'tenant_paths' => [['path' => '/p/{tenantSlug}', 'name_prefix' => 'app_']]],
        );

        self::assertSame('/p/{tenantSlug}/task/{taskId}', $loader->load('.')->get('app_nowo_task_board_task')?->getPath());
    }
}
