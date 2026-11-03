<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The demo kernel boots with the bundle under test (path repository, REQ-DEMO-007) and exposes its manage routes
 * through the documented `type: nowo_task_board` import.
 */
final class TaskBoardRoutesTest extends KernelTestCase
{
    public function testTheManageRoutesAreImported(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = $router->getRouteCollection();
        self::assertSame('/tools/task-board', $routes->get('nowo_task_board_index')?->getPath());
        self::assertSame('/tools/task-board/board/{boardId}', $routes->get('nowo_task_board_board')?->getPath());
        self::assertSame('/tools/task-board/task/{taskId}', $routes->get('nowo_task_board_task')?->getPath());
    }

    public function testTheBundleTemplatesCompile(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        foreach (['index', 'board', 'list', 'gantt', 'task', 'import'] as $page) {
            $twig->load('@NowoTaskBoardBundle/manage/' . $page . '.html.twig');
        }

        $this->addToAssertionCount(1);
    }
}
