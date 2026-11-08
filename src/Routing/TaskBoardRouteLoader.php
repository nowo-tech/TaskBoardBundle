<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use Nowo\TaskBoardBundle\Api\Controller\TaskApiIdentityController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiReadController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiWriteController;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Controller\TaskBoardConnectionController;
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
    /** Since 2.2.0 (spec 213): name => [path after `api.prefix`, controller, action, method (GET when absent)]. */
    private const API_ROUTES = [
        'user'          => ['/v2/user', TaskApiIdentityController::class, 'user'],
        'team'          => ['/v2/team', TaskApiIdentityController::class, 'teams'],
        'spaces'        => ['/v2/team/{teamId}/space', TaskApiReadController::class, 'spaces'],
        'team_tasks'    => ['/v2/team/{teamId}/task', TaskApiReadController::class, 'teamTasks'],
        'time_entries'  => ['/v2/team/{teamId}/time_entries', TaskApiReadController::class, 'timeEntries'],
        'space'         => ['/v2/space/{spaceId}', TaskApiReadController::class, 'space'],
        'folders'       => ['/v2/space/{spaceId}/folder', TaskApiReadController::class, 'folders'],
        'lists'         => ['/v2/space/{spaceId}/list', TaskApiReadController::class, 'lists'],
        'space_tags'    => ['/v2/space/{spaceId}/tag', TaskApiReadController::class, 'tags'],
        'list'          => ['/v2/list/{listId}', TaskApiReadController::class, 'list'],
        'list_tasks'    => ['/v2/list/{listId}/task', TaskApiReadController::class, 'listTasks'],
        'list_fields'   => ['/v2/list/{listId}/field', TaskApiReadController::class, 'fields'],
        'task'          => ['/v2/task/{taskId}', TaskApiReadController::class, 'task'],
        'task_comments' => ['/v2/task/{taskId}/comment', TaskApiReadController::class, 'comments'],
        'task_members'  => ['/v2/task/{taskId}/member', TaskApiReadController::class, 'members'],
        // Since 2.2.0 (spec 213): writes.
        'task_create'       => ['/v2/list/{listId}/task', TaskApiWriteController::class, 'createTask', 'POST'],
        'task_update'       => ['/v2/task/{taskId}', TaskApiWriteController::class, 'updateTask', 'PUT'],
        'task_delete'       => ['/v2/task/{taskId}', TaskApiWriteController::class, 'deleteTask', 'DELETE'],
        'comment_create'    => ['/v2/task/{taskId}/comment', TaskApiWriteController::class, 'addComment', 'POST'],
        'tag_add'           => ['/v2/task/{taskId}/tag/{tagName}', TaskApiWriteController::class, 'addTag', 'POST'],
        'tag_remove'        => ['/v2/task/{taskId}/tag/{tagName}', TaskApiWriteController::class, 'removeTag', 'DELETE'],
        'field_set'         => ['/v2/task/{taskId}/field/{fieldId}', TaskApiWriteController::class, 'setField', 'POST'],
        'dependency_add'    => ['/v2/task/{taskId}/dependency', TaskApiWriteController::class, 'addDependency', 'POST'],
        'dependency_remove' => ['/v2/task/{taskId}/dependency', TaskApiWriteController::class, 'removeDependency', 'DELETE'],
        'link_add'          => ['/v2/task/{taskId}/link/{linksTo}', TaskApiWriteController::class, 'addLink', 'POST'],
        'link_remove'       => ['/v2/task/{taskId}/link/{linksTo}', TaskApiWriteController::class, 'removeLink', 'DELETE'],
        'checklist_create'  => ['/v2/task/{taskId}/checklist', TaskApiWriteController::class, 'createChecklist', 'POST'],
        'checklist_update'  => ['/v2/checklist/{checklistId}', TaskApiWriteController::class, 'updateChecklist', 'PUT'],
        'checklist_delete'  => ['/v2/checklist/{checklistId}', TaskApiWriteController::class, 'deleteChecklist', 'DELETE'],
        'item_create'       => ['/v2/checklist/{checklistId}/checklist_item', TaskApiWriteController::class, 'createItem', 'POST'],
        'item_update'       => ['/v2/checklist/{checklistId}/checklist_item/{itemId}', TaskApiWriteController::class, 'updateItem', 'PUT'],
        'item_delete'       => ['/v2/checklist/{checklistId}/checklist_item/{itemId}', TaskApiWriteController::class, 'deleteItem', 'DELETE'],
        'attachment_create' => ['/v2/task/{taskId}/attachment', TaskApiWriteController::class, 'addAttachment', 'POST'],
        'time_log'          => ['/v2/team/{teamId}/time_entries', TaskApiWriteController::class, 'logTime', 'POST'],
        'time_delete'       => ['/v2/team/{teamId}/time_entries/{timerId}', TaskApiWriteController::class, 'deleteTime', 'DELETE'],
    ];

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
        // Since 1.23: the provider connection routes exist only with `sync.enabled`.
        private readonly bool $syncEnabled = false,
        // Since 2.1.0 (spec 214): these routes exist only with their option, so a host's route lists stay as they are.
        private readonly bool $taskNumbersEnabled = false,
        private readonly bool $calendarEnabled = false,
        // Since 2.2.0 (spec 213): the API's base path with `api.enabled`; null: no API route at all.
        private readonly ?string $apiPrefix = null,
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
        $this->addRoute($collection, 'task_import', 'importIntoTask', ['POST']);
        $this->addRoute($collection, 'task_time', 'logTime', ['POST']);
        $this->addRoute($collection, 'task_time_remove', 'removeTimeEntry', ['POST']);
        // Since 1.16.
        $this->addRoute($collection, 'task_comment', 'addComment', ['POST']);
        $this->addRoute($collection, 'task_comment_edit', 'editComment', ['POST']);
        $this->addRoute($collection, 'task_comment_delete', 'deleteComment', ['POST']);
        // Since 1.23.
        $this->addRoute($collection, 'task_restore', 'restoreTask', ['POST']);
        if ($this->taskNumbersEnabled) {
            $this->addRoute($collection, 'task_by_number', 'taskByNumber', ['GET'], requirements: ['number' => '[1-9][0-9]{0,8}']);
        }
        if ($this->calendarEnabled) {
            $this->addRoute($collection, 'calendar', 'calendarView', ['GET']);
            $this->addRoute($collection, 'task_schedule', 'scheduleTask', ['POST']);
        }
        if ($this->syncEnabled) {
            $this->addRoute($collection, 'board_connection', 'connection', ['GET'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_containers', 'containers', ['POST'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_link', 'link', ['POST'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_refresh', 'refresh', ['POST'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_progress', 'progress', ['GET'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_cancel', 'cancel', ['POST'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_unlink', 'unlink', ['POST'], TaskBoardConnectionController::class);
            // Since 1.27.0 (TB-SYNC-2).
            $this->addRoute($collection, 'board_connection_people', 'people', ['GET'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_people_save', 'peopleSave', ['POST'], TaskBoardConnectionController::class);
            $this->addRoute($collection, 'board_connection_people_former', 'peopleFormer', ['POST'], TaskBoardConnectionController::class);
        }

        $this->addTenantCopies($collection);
        $this->addApiRoutes($collection);

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_task_board';
    }

    /**
     * @param list<string> $methods
     * @param array<string, string> $requirements
     */
    private function addRoute(RouteCollection $collection, string $key, string $action, array $methods, string $controller = TaskBoardManageController::class, array $requirements = []): void
    {
        if (!isset($this->routes[$key])) {
            return;
        }

        $collection->add(
            $this->routes[$key]['name'],
            new Route(
                $this->routePrefix . $this->routes[$key]['path'],
                ['_controller' => $controller . '::' . $action],
                $requirements,
                [],
                '',
                [],
                $methods,
            ),
        );
    }

    /**
     * Since 2.2.0 (spec 213): stateless, one path per tenant-free resource: no `route_prefix` and no tenant copies. After the
     * copies, which only read `routes`.
     */
    private function addApiRoutes(RouteCollection $collection): void
    {
        if ($this->apiPrefix === null) {
            return;
        }

        foreach (self::API_ROUTES as $name => $route) {
            [$path, $controller, $action] = $route;
            $collection->add('nowo_task_board_api_' . $name, new Route(
                $this->apiPrefix . $path,
                ['_controller' => $controller . '::' . $action, TaskApiRequestListener::ROUTE_FLAG => true, '_stateless' => true],
                ['teamId' => '[^/]+', 'spaceId' => '[^/]+', 'listId' => '[^/]+', 'taskId' => '[^/]+', 'tagName' => '[^/]+', 'fieldId' => '[^/]+', 'linksTo' => '[^/]+', 'checklistId' => '[^/]+', 'itemId' => '[^/]+', 'timerId' => '[^/]+'],
                [],
                '',
                [],
                [$route[3] ?? 'GET'],
            ));
        }
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
