<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Loads TaskBoard routes from bundle configuration and, with `tenancy`, a copy of every manage route under each
 * `tenancy.tenant_paths` entry (`<name_prefix><route name>`, path `<path><route path minus routes.index.path>`).
 */
final class TaskBoardRouteLoader extends Loader
{
    /** Default base path of the manage routes (routes.index.path). */
    private const DEFAULT_BASE = '/tools/task-board';

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array{enabled?: bool, tenant_paths?: list<array{path: string, name_prefix: string, slug_prefix?: string, slug_requirement?: string|null}>} $tenancy
     *                                                                                                                                                            `tenancy` (since 1.10)
     */
    public function __construct(
        private readonly array $routes,
        private readonly string $routePrefix,
        private readonly array $tenancy = [],
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();

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
        // Since 1.11.
        $this->addRoute($collection, 'column_delete', 'deleteColumn', ['POST']);
        $this->addRoute($collection, 'column_set_create', 'createColumnSet', ['POST']);
        $this->addRoute($collection, 'column_set_apply', 'applyColumnSet', ['POST']);
        $this->addRoute($collection, 'column_set_delete', 'deleteColumnSet', ['POST']);
        $this->addRoute($collection, 'task_panel', 'taskPanel', ['GET']);
        // Since 1.15.
        $this->addRoute($collection, 'task_dependency', 'addDependency', ['POST']);
        $this->addRoute($collection, 'task_dependency_remove', 'removeDependency', ['POST']);
        $this->addRoute($collection, 'task_time', 'logTime', ['POST']);
        $this->addRoute($collection, 'task_time_remove', 'removeTimeEntry', ['POST']);
        // Since 1.16.
        $this->addRoute($collection, 'task_comment', 'addComment', ['POST']);
        $this->addRoute($collection, 'task_comment_edit', 'editComment', ['POST']);
        $this->addRoute($collection, 'task_comment_delete', 'deleteComment', ['POST']);

        $this->addTenantCopies($collection);

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

    /**
     * `tenancy.tenant_paths`: every manage route once more per tenant path, with `{tenantSlug}` in its path.
     */
    private function addTenantCopies(RouteCollection $collection): void
    {
        if (!($this->tenancy['enabled'] ?? false)) {
            return;
        }

        $originals = $collection->all();
        foreach ($this->tenancy['tenant_paths'] ?? [] as $tenantPath) {
            $requirement = TenantPaths::slugRequirement($tenantPath);
            foreach ($this->routes as $route) {
                $original = $originals[$route['name']] ?? null;
                if (!$original instanceof Route) {
                    continue;
                }

                $copy = clone $original;
                $copy->setPath(rtrim($tenantPath['path'], '/') . $this->relativePath($route['path']));
                $copy->setRequirement('tenantSlug', $requirement);
                $collection->add($tenantPath['name_prefix'] . $route['name'], $copy);
            }
        }
    }

    /**
     * A route path relative to the routes' base path (routes.index.path); a route outside that base keeps its path.
     */
    private function relativePath(string $path): string
    {
        $base = rtrim($this->routes['index']['path'] ?? self::DEFAULT_BASE, '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            return substr($path, strlen($base));
        }

        return $path;
    }
}
