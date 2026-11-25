<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use LogicException;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiIdentityController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiManagementController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiQuestionController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiReadController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiTemplateController;
use Nowo\TaskBoardBundle\Api\Controller\TaskApiWriteController;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Controller\TaskBoardConnectionController;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\Controller\TaskBoardMultiBoardController;
use Nowo\TaskBoardBundle\Controller\TaskCopyController;
use Nowo\TaskBoardBundle\Controller\TaskMenuController;
use Nowo\TaskBoardBundle\Controller\TaskQuestionController;
use Nowo\TaskBoardBundle\Controller\TaskRecurrenceController;
use Nowo\TaskBoardBundle\Controller\TaskSyncImportPlanController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Route loader (type `nowo_task_board`) of the manage UI, the provider connections, the multi-board list and the Task API.
 * Since 2.21.1 (REQ-SF-004) the routes are declared with `#[Route]` attributes on the controllers (methods, requirements,
 * defaults, `_controller`, default path and name); this loader reads them and applies the configuration as before: the
 * name and path of each manage route (`routes.*`), `route_prefix`, the feature switches, `multi_board.path`, `api.prefix`
 * and, with `tenancy`, a copy of every manage route under each `tenancy.tenant_paths` entry (`<name_prefix><route name>`,
 * path `<path><route path minus routes.index.path>`). The route collection is the 2.21.0 one, route for route and in the
 * same order (pinned by tests/Fixtures/Routing/route-collections.json); hosts keep their `type: nowo_task_board` import.
 */
final class TaskBoardRouteLoader extends Loader
{
    /** Since 2.1.0 (spec 214): `{number}` of task_by_number. */
    public const NUMBER_REQUIREMENT = '[1-9][0-9]{0,8}';

    /** Since 2.3.0 (spec 215): `{action}` of task_recurrence_action. */
    public const RECURRENCE_ACTION_REQUIREMENT = 'pause|resume|stop|skip|unskip';

    /** Since 2.13.0: `{format}` of the multi-board export. */
    public const EXPORT_FORMAT_REQUIREMENT = 'csv|xlsx';

    /** Since 2.2.0 (spec 213): every placeholder of the Task API routes (each API route carries all of them). */
    public const API_REQUIREMENTS = ['teamId' => '[^/]+', 'spaceId' => '[^/]+', 'listId' => '[^/]+', 'taskId' => '[^/]+', 'tagName' => '[^/]+', 'fieldId' => '[^/]+', 'linksTo' => '[^/]+', 'checklistId' => '[^/]+', 'itemId' => '[^/]+', 'timerId' => '[^/]+', 'statusId' => '[^/]+', 'commentId' => '[^/]+', 'folderId' => '[^/]+'];

    /** Since 2.2.0 (spec 213): the Task API routes are flagged for its request listener and stateless. */
    public const API_DEFAULTS = [TaskApiRequestListener::ROUTE_FLAG => true, '_stateless' => true];

    /** Since 2.21.1: the controllers whose `#[Route]` attributes are read. */
    public const CONTROLLERS = [
        TaskBoardManageController::class,
        TaskCopyController::class,
        TaskRecurrenceController::class,
        TaskMenuController::class,
        TaskBoardConnectionController::class,
        TaskBoardMultiBoardController::class,
        TaskApiIdentityController::class,
        TaskApiReadController::class,
        TaskApiWriteController::class,
        TaskApiManagementController::class,
        TaskApiTemplateController::class,
        // Since 2.23.0 (Beacon spec 242).
        TaskQuestionController::class,
        TaskApiQuestionController::class,
        // Since 3.2.0 (CU-2).
        TaskSyncImportPlanController::class,
    ];

    /**
     * Since 2.2.0 (spec 213): name (after `nowo_task_board_api_`) => controller action, in collection order; the path
     * after `api.prefix`, the method and the requirements are on the action's `#[Route]`.
     */
    private const API_ROUTES = [
        'user'       => TaskApiIdentityController::class . '::user',
        'team'       => TaskApiIdentityController::class . '::teams',
        'spaces'     => TaskApiReadController::class . '::spaces',
        'team_tasks' => TaskApiReadController::class . '::teamTasks',
        // Since 2.16.0 (spec 234, FR-025).
        'team_members'  => TaskApiIdentityController::class . '::members',
        'time_entries'  => TaskApiReadController::class . '::timeEntries',
        'space'         => TaskApiReadController::class . '::space',
        'folders'       => TaskApiReadController::class . '::folders',
        'lists'         => TaskApiReadController::class . '::lists',
        'space_tags'    => TaskApiReadController::class . '::tags',
        'list'          => TaskApiReadController::class . '::list',
        'list_tasks'    => TaskApiReadController::class . '::listTasks',
        'list_fields'   => TaskApiReadController::class . '::fields',
        'task'          => TaskApiReadController::class . '::task',
        'task_comments' => TaskApiReadController::class . '::comments',
        'task_members'  => TaskApiReadController::class . '::members',
        // Since 2.2.0 (spec 213): writes.
        'task_create' => TaskApiWriteController::class . '::createTask',
        'task_update' => TaskApiWriteController::class . '::updateTask',
        'task_delete' => TaskApiWriteController::class . '::deleteTask',
        // Since 2.16.0 (spec 234, FR-021): the atomic bulk update.
        'task_bulk'      => TaskApiWriteController::class . '::updateTasks',
        'comment_create' => TaskApiWriteController::class . '::addComment',
        // Since 2.16.0 (spec 234).
        'comment_update'    => TaskApiWriteController::class . '::updateComment',
        'tag_add'           => TaskApiWriteController::class . '::addTag',
        'tag_remove'        => TaskApiWriteController::class . '::removeTag',
        'field_set'         => TaskApiWriteController::class . '::setField',
        'dependency_add'    => TaskApiWriteController::class . '::addDependency',
        'dependency_remove' => TaskApiWriteController::class . '::removeDependency',
        'link_add'          => TaskApiWriteController::class . '::addLink',
        'link_remove'       => TaskApiWriteController::class . '::removeLink',
        'checklist_create'  => TaskApiWriteController::class . '::createChecklist',
        'checklist_update'  => TaskApiWriteController::class . '::updateChecklist',
        'checklist_delete'  => TaskApiWriteController::class . '::deleteChecklist',
        'item_create'       => TaskApiWriteController::class . '::createItem',
        'item_update'       => TaskApiWriteController::class . '::updateItem',
        'item_delete'       => TaskApiWriteController::class . '::deleteItem',
        'attachment_create' => TaskApiWriteController::class . '::addAttachment',
        'time_log'          => TaskApiWriteController::class . '::logTime',
        'time_delete'       => TaskApiWriteController::class . '::deleteTime',
        // Since 2.12.0: the billable flag of an own entry.
        'time_update' => TaskApiWriteController::class . '::updateTime',
        // Since 2.2.0 (spec 213): management. Columns are statuses, on paths of this API's own (owner, Q-O2).
        'board_create'  => TaskApiManagementController::class . '::createBoard',
        'board_update'  => TaskApiManagementController::class . '::updateBoard',
        'board_delete'  => TaskApiManagementController::class . '::deleteBoard',
        'status_create' => TaskApiManagementController::class . '::createColumn',
        // Before status_update: "order" is not a column id.
        'status_order'  => TaskApiManagementController::class . '::reorderColumns',
        'status_update' => TaskApiManagementController::class . '::updateColumn',
        'status_delete' => TaskApiManagementController::class . '::deleteColumn',
        // Since 2.19.0 (Beacon spec 234, FR-031): folders.
        'folder'             => TaskApiReadController::class . '::folder',
        'folder_lists'       => TaskApiReadController::class . '::folderLists',
        'folder_create'      => TaskApiManagementController::class . '::createFolder',
        'folder_update'      => TaskApiManagementController::class . '::updateFolder',
        'folder_delete'      => TaskApiManagementController::class . '::deleteFolder',
        'folder_list_create' => TaskApiManagementController::class . '::createFolderList',
    ];

    /** Since 2.23.0 (Beacon spec 242): with `task_questions.enabled` only. */
    private const API_QUESTION_ROUTES = [
        'task_questions'    => TaskApiQuestionController::class . '::taskQuestions',
        'question_ask'      => TaskApiQuestionController::class . '::ask',
        'question_answer'   => TaskApiQuestionController::class . '::answer',
        'question_withdraw' => TaskApiQuestionController::class . '::withdraw',
        'list_questions'    => TaskApiQuestionController::class . '::listQuestions',
        'my_questions'      => TaskApiQuestionController::class . '::myQuestions',
    ];

    /** Since 2.16.0 (Beacon spec 234, FR-061): with `task_templates.enabled` only. */
    private const API_TEMPLATE_ROUTES = [
        'task_templates' => TaskApiTemplateController::class . '::templates',
    ];

    /** Default base path of the manage routes (routes.index.path). */
    private const DEFAULT_BASE = '/tools/task-board';

    private readonly ControllerAttributeRouteLoader $attributeLoader;

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
        // Since 2.3.0 (spec 217): task_move_board and task_duplicate exist only with `move.enabled`.
        private readonly bool $moveEnabled = false,
        // Since 2.3.0 (spec 215): task_recurrence and task_recurrence_action exist only with `recurrence.enabled`.
        private readonly bool $recurrenceEnabled = false,
        // Since 2.13.0 (Beacon D-20261112-01): `multi_board.path` with `multi_board.enabled`; null: no multi-board route.
        private readonly ?string $multiBoardPath = null,
        // Since 2.16.0 (Beacon spec 234): the API's template list exists only with `task_templates.enabled`.
        private readonly bool $templatesEnabled = false,
        // Since 2.23.0 (Beacon spec 242): the question routes (pages and API) exist only with `task_questions.enabled`.
        private readonly bool $questionsEnabled = false,
        // Since 3.2.0 (CU-2): the import plan routes exist only with `sync.enabled` and `sync.plans.enabled`.
        private readonly bool $importPlansEnabled = false,
    ) {
        parent::__construct();
        $this->attributeLoader = new ControllerAttributeRouteLoader();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();
        $attributes = $this->attributeRoutes($collection);

        $this->addRoute($collection, $attributes, 'index', TaskBoardManageController::class . '::index');
        $this->addRoute($collection, $attributes, 'board', TaskBoardManageController::class . '::board');
        $this->addRoute($collection, $attributes, 'list', TaskBoardManageController::class . '::listView');
        $this->addRoute($collection, $attributes, 'gantt', TaskBoardManageController::class . '::ganttView');
        $this->addRoute($collection, $attributes, 'task', TaskBoardManageController::class . '::task');
        $this->addRoute($collection, $attributes, 'board_create', TaskBoardManageController::class . '::createBoard');
        $this->addRoute($collection, $attributes, 'task_create', TaskBoardManageController::class . '::createTask');
        $this->addRoute($collection, $attributes, 'task_move', TaskBoardManageController::class . '::moveTask');
        $this->addRoute($collection, $attributes, 'task_advance', TaskBoardManageController::class . '::advanceTask');
        $this->addRoute($collection, $attributes, 'task_link', TaskBoardManageController::class . '::attachLink');
        $this->addRoute($collection, $attributes, 'task_link_update', TaskBoardManageController::class . '::updateLink');
        $this->addRoute($collection, $attributes, 'task_link_remove', TaskBoardManageController::class . '::removeLink');
        $this->addRoute($collection, $attributes, 'task_member', TaskBoardManageController::class . '::addMember');
        $this->addRoute($collection, $attributes, 'task_member_remove', TaskBoardManageController::class . '::removeMember');
        $this->addRoute($collection, $attributes, 'task_subtask', TaskBoardManageController::class . '::createSubtask');
        $this->addRoute($collection, $attributes, 'task_priority', TaskBoardManageController::class . '::updatePriority');
        $this->addRoute($collection, $attributes, 'column_create', TaskBoardManageController::class . '::createColumn');
        $this->addRoute($collection, $attributes, 'column_update', TaskBoardManageController::class . '::updateColumn');
        $this->addRoute($collection, $attributes, 'column_reorder', TaskBoardManageController::class . '::reorderColumns');
        $this->addRoute($collection, $attributes, 'board_import', TaskBoardManageController::class . '::importBoard');
        // Since 1.11.
        $this->addRoute($collection, $attributes, 'column_delete', TaskBoardManageController::class . '::deleteColumn');
        $this->addRoute($collection, $attributes, 'column_set_create', TaskBoardManageController::class . '::createColumnSet');
        $this->addRoute($collection, $attributes, 'column_set_apply', TaskBoardManageController::class . '::applyColumnSet');
        $this->addRoute($collection, $attributes, 'column_set_delete', TaskBoardManageController::class . '::deleteColumnSet');
        $this->addRoute($collection, $attributes, 'task_panel', TaskBoardManageController::class . '::taskPanel');
        // Since 1.15.
        $this->addRoute($collection, $attributes, 'task_dependency', TaskBoardManageController::class . '::addDependency');
        $this->addRoute($collection, $attributes, 'task_dependency_remove', TaskBoardManageController::class . '::removeDependency');
        $this->addRoute($collection, $attributes, 'task_import', TaskBoardManageController::class . '::importIntoTask');
        $this->addRoute($collection, $attributes, 'task_time', TaskBoardManageController::class . '::logTime');
        $this->addRoute($collection, $attributes, 'task_time_remove', TaskBoardManageController::class . '::removeTimeEntry');
        // Since 2.12.0.
        $this->addRoute($collection, $attributes, 'task_time_billable', TaskBoardManageController::class . '::setTimeEntryBillable');
        // Since 1.16.
        $this->addRoute($collection, $attributes, 'task_comment', TaskBoardManageController::class . '::addComment');
        $this->addRoute($collection, $attributes, 'task_comment_edit', TaskBoardManageController::class . '::editComment');
        $this->addRoute($collection, $attributes, 'task_comment_delete', TaskBoardManageController::class . '::deleteComment');
        // Since 1.23.
        $this->addRoute($collection, $attributes, 'task_restore', TaskBoardManageController::class . '::restoreTask');
        // Since 2.22.0.
        $this->addRoute($collection, $attributes, 'task_menu', TaskMenuController::class . '::act');
        if ($this->taskNumbersEnabled) {
            $this->addRoute($collection, $attributes, 'task_by_number', TaskBoardManageController::class . '::taskByNumber');
        }
        if ($this->calendarEnabled) {
            $this->addRoute($collection, $attributes, 'calendar', TaskBoardManageController::class . '::calendarView');
            $this->addRoute($collection, $attributes, 'task_schedule', TaskBoardManageController::class . '::scheduleTask');
            // Since 2.24.0: moves an event of a calendar event source.
            $this->addRoute($collection, $attributes, 'calendar_event_reschedule', TaskBoardManageController::class . '::rescheduleCalendarEvent');
        }
        if ($this->moveEnabled) {
            $this->addRoute($collection, $attributes, 'task_move_board', TaskCopyController::class . '::move');
            $this->addRoute($collection, $attributes, 'task_duplicate', TaskCopyController::class . '::duplicate');
        }
        if ($this->recurrenceEnabled) {
            $this->addRoute($collection, $attributes, 'task_recurrence', TaskRecurrenceController::class . '::edit');
            $this->addRoute($collection, $attributes, 'task_recurrence_action', TaskRecurrenceController::class . '::action');
        }
        if ($this->questionsEnabled) {
            $this->addRoute($collection, $attributes, 'task_question_ask', TaskQuestionController::class . '::ask');
            $this->addRoute($collection, $attributes, 'task_question_answer', TaskQuestionController::class . '::answer');
            $this->addRoute($collection, $attributes, 'task_question_withdraw', TaskQuestionController::class . '::withdraw');
            $this->addRoute($collection, $attributes, 'task_question_publish', TaskQuestionController::class . '::publish');
            $this->addRoute($collection, $attributes, 'my_questions', TaskQuestionController::class . '::mine');
        }
        if ($this->syncEnabled) {
            $this->addRoute($collection, $attributes, 'board_connection', TaskBoardConnectionController::class . '::connection');
            $this->addRoute($collection, $attributes, 'board_connection_containers', TaskBoardConnectionController::class . '::containers');
            $this->addRoute($collection, $attributes, 'board_connection_link', TaskBoardConnectionController::class . '::link');
            $this->addRoute($collection, $attributes, 'board_connection_refresh', TaskBoardConnectionController::class . '::refresh');
            $this->addRoute($collection, $attributes, 'board_connection_progress', TaskBoardConnectionController::class . '::progress');
            $this->addRoute($collection, $attributes, 'board_connection_cancel', TaskBoardConnectionController::class . '::cancel');
            $this->addRoute($collection, $attributes, 'board_connection_unlink', TaskBoardConnectionController::class . '::unlink');
            // Since 1.27.0 (TB-SYNC-2).
            $this->addRoute($collection, $attributes, 'board_connection_people', TaskBoardConnectionController::class . '::people');
            $this->addRoute($collection, $attributes, 'board_connection_people_save', TaskBoardConnectionController::class . '::peopleSave');
            $this->addRoute($collection, $attributes, 'board_connection_people_former', TaskBoardConnectionController::class . '::peopleFormer');
            if ($this->importPlansEnabled) {
                // Since 3.2.0 (CU-2): the guided import.
                foreach (['import_plans' => 'plans', 'import_plan_new' => 'create', 'import_plan' => 'show', 'import_plan_step' => 'save', 'import_plan_start' => 'start', 'import_plan_progress' => 'progress', 'import_plan_cancel' => 'cancel', 'import_plan_resume' => 'resume', 'import_plan_delete' => 'delete', 'import_plan_report' => 'report'] as $key => $action) {
                    $this->addRoute($collection, $attributes, $key, TaskSyncImportPlanController::class . '::' . $action);
                }
            }
        }

        $this->addTenantCopies($collection);
        $this->addMultiBoardRoutes($collection, $attributes);
        $this->addApiRoutes($collection, $attributes);

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_task_board';
    }

    /**
     * The routes declared by the controller attributes, by `_controller`; the controller class resources go to
     * `$collection` (router cache freshness in debug).
     *
     * @return array<string, Route>
     */
    private function attributeRoutes(RouteCollection $collection): array
    {
        $routes = [];
        foreach (self::CONTROLLERS as $controller) {
            $loaded = $this->attributeLoader->load($controller);
            foreach ($loaded->getResources() as $resource) {
                $collection->addResource($resource);
            }
            foreach ($loaded->all() as $route) {
                $routes[(string) $route->getDefault('_controller')] = $route;
            }
        }

        return $routes;
    }

    /**
     * A manage route: its configured name and path (with the route prefix), everything else from the action's attribute.
     * A key without a configuration entry (a hand-built loader) is skipped.
     *
     * @param array<string, Route> $attributes
     */
    private function addRoute(RouteCollection $collection, array $attributes, string $key, string $controller): void
    {
        if (!isset($this->routes[$key])) {
            return;
        }

        $collection->add($this->routes[$key]['name'], $this->fromAttribute($attributes, $controller, $this->routePrefix . $this->routes[$key]['path']));
    }

    /**
     * A route at `$path` with the methods, requirements, defaults, options, host, schemes and condition of the
     * controller action's `#[Route]` (`_controller` first, as the 2.21.0 loader wrote it).
     *
     * @param array<string, Route> $attributes
     */
    private function fromAttribute(array $attributes, string $controller, string $path): Route
    {
        $attribute = $attributes[$controller] ?? throw new LogicException(sprintf('No #[Route] attribute on "%s".', $controller));

        return new Route(
            $path,
            ['_controller' => $controller] + $attribute->getDefaults(),
            $attribute->getRequirements(),
            $attribute->getOptions(),
            $attribute->getHost(),
            $attribute->getSchemes(),
            $attribute->getMethods(),
            $attribute->getCondition(),
        );
    }

    /**
     * Since 2.13.0: the multi-board list and its export, after the tenant copies: they span tenants, so they are never
     * copied under a tenant path (the provider scopes them, with the path's own variables if it has any). The export is
     * `<path>/export.{format}`.
     *
     * @param array<string, Route> $attributes
     */
    private function addMultiBoardRoutes(RouteCollection $collection, array $attributes): void
    {
        if ($this->multiBoardPath === null) {
            return;
        }

        $path = $this->routePrefix . rtrim($this->multiBoardPath, '/');
        $collection->add(TaskBoardMultiBoardController::ROUTE, $this->fromAttribute($attributes, TaskBoardMultiBoardController::class . '::tasks', $path === '' ? '/' : $path));
        $collection->add(TaskBoardMultiBoardController::ROUTE_EXPORT, $this->fromAttribute($attributes, TaskBoardMultiBoardController::class . '::export', $path . '/export.{format}'));
    }

    /**
     * Since 2.2.0 (spec 213): stateless, one path per tenant-free resource: no `route_prefix` and no tenant copies. After the
     * copies, which only read `routes`. The path after `api.prefix` is the attribute's.
     *
     * @param array<string, Route> $attributes
     */
    private function addApiRoutes(RouteCollection $collection, array $attributes): void
    {
        if ($this->apiPrefix === null) {
            return;
        }

        $routes = self::API_ROUTES + ($this->templatesEnabled ? self::API_TEMPLATE_ROUTES : []) + ($this->questionsEnabled ? self::API_QUESTION_ROUTES : []);
        foreach ($routes as $name => $controller) {
            $attribute = $attributes[$controller] ?? throw new LogicException(sprintf('No #[Route] attribute on "%s".', $controller));
            $collection->add('nowo_task_board_api_' . $name, $this->fromAttribute($attributes, $controller, $this->apiPrefix . $attribute->getPath()));
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
