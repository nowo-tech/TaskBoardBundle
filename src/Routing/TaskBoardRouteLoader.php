<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads TaskBoard routes from bundle configuration.
 */
final class TaskBoardRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly array $routes,
        private readonly string $routePrefix,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('TaskBoard routes already loaded.');
        }

        $this->loaded = true;
        $collection   = new RouteCollection();

        $this->addRoute($collection, 'index', 'index', ['GET']);
        $this->addRoute($collection, 'board', 'board', ['GET']);
        $this->addRoute($collection, 'list', 'listView', ['GET']);
        $this->addRoute($collection, 'gantt', 'ganttView', ['GET']);
        $this->addRoute($collection, 'task', 'task', ['GET', 'POST']);
        $this->addRoute($collection, 'board_create', 'createBoard', ['POST']);
        $this->addRoute($collection, 'task_create', 'createTask', ['POST']);
        $this->addRoute($collection, 'task_move', 'moveTask', ['POST']);
        $this->addRoute($collection, 'task_advance', 'advanceTask', ['POST']);
        $this->addRoute($collection, 'task_link', 'attachLink', ['POST']);
        $this->addRoute($collection, 'task_link_update', 'updateLink', ['POST']);
        $this->addRoute($collection, 'task_link_remove', 'removeLink', ['POST']);
        $this->addRoute($collection, 'task_member', 'addMember', ['POST']);
        $this->addRoute($collection, 'task_member_remove', 'removeMember', ['POST']);
        $this->addRoute($collection, 'task_subtask', 'createSubtask', ['POST']);
        $this->addRoute($collection, 'task_priority', 'updatePriority', ['POST']);
        $this->addRoute($collection, 'column_create', 'createColumn', ['POST']);
        $this->addRoute($collection, 'column_update', 'updateColumn', ['POST']);
        $this->addRoute($collection, 'column_reorder', 'reorderColumns', ['POST']);
        $this->addRoute($collection, 'board_import', 'importBoard', ['GET', 'POST']);

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_task_board';
    }

    /**
     * @param list<string> $methods
     */
    private function addRoute(RouteCollection $collection, string $key, string $action, array $methods): void
    {
        if (!isset($this->routes[$key])) {
            return;
        }

        $collection->add(
            $this->routes[$key]['name'],
            new Route(
                $this->routePrefix . $this->routes[$key]['path'],
                ['_controller' => TaskBoardManageController::class . '::' . $action],
                [],
                [],
                '',
                [],
                $methods,
            ),
        );
    }
}
