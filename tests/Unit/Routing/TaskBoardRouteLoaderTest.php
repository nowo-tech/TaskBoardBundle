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
}
