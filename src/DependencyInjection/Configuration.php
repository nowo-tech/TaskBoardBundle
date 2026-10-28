<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_string;
use function preg_match;
use function str_contains;

/**
 * Configuration tree for TaskBoardBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_task_board';

    /** @var list<string> Host CSS stacks accepted by templates.css_framework (REQ-UI-001). */
    public const CSS_FRAMEWORKS = [
        'bootstrap',
        'bootstrap4',
        'bootstrap5',
        'tailwind',
        'foundation',
        'custom',
        'tabler',
        'none',
    ];

    /** Shape of a column icon name (`<set>:<name>`, since 1.11). */
    public const ICON_PATTERN = '/^[a-z0-9-]+:[a-z0-9-]+$/D';

    /** @var list<string> Curated Tabler icons of `board.column_icons` (since 1.11). */
    public const DEFAULT_COLUMN_ICONS = [
        'tabler:circle',
        'tabler:circle-dashed',
        'tabler:circle-check',
        'tabler:check',
        'tabler:x',
        'tabler:inbox',
        'tabler:list-check',
        'tabler:player-play',
        'tabler:progress',
        'tabler:hourglass',
        'tabler:clock',
        'tabler:calendar',
        'tabler:eye',
        'tabler:message-circle',
        'tabler:user',
        'tabler:bug',
        'tabler:flame',
        'tabler:alert-triangle',
        'tabler:flag',
        'tabler:star',
        'tabler:bulb',
        'tabler:code',
        'tabler:git-pull-request',
        'tabler:git-merge',
        'tabler:test-pipe',
        'tabler:rocket',
        'tabler:package',
        'tabler:lock',
        'tabler:ban',
        'tabler:archive',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('user_class')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('table_prefix')
                    ->defaultValue('task_board_')
                ->end()
                ->scalarNode('team_membership_resolver')
                    ->defaultNull()
                ->end()
                ->scalarNode('route_prefix')
                    ->defaultValue('')
                ->end()
                ->scalarNode('dashboard_route')
                    ->defaultNull()
                ->end()
                ->scalarNode('firewall')
                    ->info('Deprecated: never read by the bundle. Protect the manage routes with your own security.yaml firewall/access_control.')
                    ->setDeprecated('nowo-tech/task-board-bundle', '1.7', 'The "%node%" option at path "%path%" is deprecated and has no effect; configure the firewall and access_control in your security.yaml instead.')
                    ->defaultValue('main')
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('access_checker')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_USER'])
                        ->end()
                        ->arrayNode('create_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_USER'])
                        ->end()
                        ->arrayNode('list_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_USER'])
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('resource_scoping')
                            ->info('Restrict boards/tasks to their creator, team members, task members and manage_roles (IDOR protection).')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('manage_roles')
                            ->info('Roles that can view and edit every board regardless of ownership or team.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('index')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_index')->end()
                            ->end()
                        ->end()
                        ->arrayNode('board')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_board')->end()
                            ->end()
                        ->end()
                        ->arrayNode('list')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/list')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_list')->end()
                            ->end()
                        ->end()
                        ->arrayNode('gantt')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/gantt')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_gantt')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task')->end()
                            ->end()
                        ->end()
                        ->arrayNode('board_create')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/create')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_board_create')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_create')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/create')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_create')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_move')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/move')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_move')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_advance')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/advance')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_advance')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_link')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/link')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_link')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_link_update')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/link/{linkId}')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_link_update')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_link_remove')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/link/{linkId}/remove')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_link_remove')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_member')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/member')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_member')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_member_remove')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/member/{memberId}/remove')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_member_remove')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_subtask')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/subtask')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_subtask')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_priority')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/priority')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_priority')->end()
                            ->end()
                        ->end()
                        ->arrayNode('column_create')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/column')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_create')->end()
                            ->end()
                        ->end()
                        ->arrayNode('column_update')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/column/{columnId}')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_update')->end()
                            ->end()
                        ->end()
                        ->arrayNode('column_reorder')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/columns/reorder')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_reorder')->end()
                            ->end()
                        ->end()
                        ->arrayNode('board_import')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/import')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_board_import')->end()
                            ->end()
                        ->end()
                        // Since 1.11: column delete, column sets and the task panel fragment.
                        ->arrayNode('column_delete')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/column/{columnId}/delete')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_delete')->end()
                            ->end()
                        ->end()
                        ->arrayNode('column_set_create')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/column-sets')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_set_create')->end()
                            ->end()
                        ->end()
                        ->arrayNode('column_set_apply')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/column-sets/apply')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_set_apply')->end()
                            ->end()
                        ->end()
                        ->arrayNode('column_set_delete')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/board/{boardId}/column-sets/{setId}/delete')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_column_set_delete')->end()
                            ->end()
                        ->end()
                        ->arrayNode('task_panel')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/task-board/task/{taskId}/panel')->end()
                                ->scalarNode('name')->defaultValue('nowo_task_board_task_panel')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout')
                            ->defaultValue('@NowoTaskBoardBundle/layout.html.twig')
                            ->cannotBeEmpty()
                            ->info('Twig layout extended by manage pages via global nowo_task_board_layout (REQ-UI-001). Host apps set this to the project layout or a one-file bridge. Default is the bundle demo layout (Tabler CDN).')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultValue('tabler')
                            ->info('Host-chosen CSS stack (REQ-UI-001). Twig global nowo_task_board_css_framework. Default tabler matches the demo layout CDN. Values: bootstrap (alias of bootstrap5), bootstrap4, bootstrap5, tabler (Bootstrap-compatible), tailwind, foundation, custom, none. Manage pages ship Tabler/Bootstrap 5 classes; custom relies on host CSS / semantic nowo-ui-* classes.')
                        ->end()
                        ->scalarNode('index')->defaultValue('@NowoTaskBoardBundle/manage/index.html.twig')->end()
                        ->scalarNode('board')->defaultValue('@NowoTaskBoardBundle/manage/board.html.twig')->end()
                        ->scalarNode('list')->defaultValue('@NowoTaskBoardBundle/manage/list.html.twig')->end()
                        ->scalarNode('gantt')->defaultValue('@NowoTaskBoardBundle/manage/gantt.html.twig')->end()
                        ->scalarNode('task')->defaultValue('@NowoTaskBoardBundle/manage/task.html.twig')->end()
                        ->scalarNode('import')->defaultValue('@NowoTaskBoardBundle/manage/import.html.twig')->end()
                        ->scalarNode('task_panel')
                            ->defaultValue('@NowoTaskBoardBundle/manage/task_panel.html.twig')
                            ->info('Task editing panel without layout (since 1.11), served by the task_panel route for side drawers.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_manager')->defaultValue('default')->end()
                    ->end()
                ->end()
                ->arrayNode('tenancy')
                    ->addDefaultsIfNotSet()
                    ->info('Boards per host tenant (since 1.10): every manage route is copied under each `tenant_paths` entry (its {tenantSlug} names the tenant), a tenant sees only its boards and a board or task of another tenant answers 404.')
                    ->validate()
                        ->ifTrue(static fn (array $tenancy): bool => $tenancy['enabled'] && $tenancy['tenant_paths'] === [])
                        ->thenInvalid('nowo_task_board.tenancy.enabled needs at least one nowo_task_board.tenancy.tenant_paths entry.')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('tenant_paths')
                            ->info('URL prefixes of the tenant pages, chosen by tenant slug prefix (first match wins): each copies every manage route as <name_prefix><route name> under <path>; the URL generator sends links of a matching slug to the copy and fills {tenantSlug} from the current tenant page.')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('path')
                                        ->isRequired()
                                        ->cannotBeEmpty()
                                        ->info('Replaces the routes\' base path (routes.index.path) and holds {tenantSlug}, e.g. /projects/{tenantSlug}/tasks.')
                                        ->validate()
                                            ->ifTrue(static fn (string $path): bool => !str_contains($path, '{tenantSlug}'))
                                            ->thenInvalid('A tenant path needs the {tenantSlug} placeholder: %s')
                                        ->end()
                                    ->end()
                                    ->scalarNode('name_prefix')->isRequired()->cannotBeEmpty()->info('Prefix of the copied route names, e.g. app_project_ (app_project_nowo_task_board_board).')->end()
                                    ->scalarNode('slug_prefix')->defaultValue('')->info('Slugs this path serves (prefix match); empty: every slug.')->end()
                                    ->scalarNode('slug_requirement')->defaultNull()->info('Route requirement of {tenantSlug}; default: the slug prefix then [A-Za-z0-9_-]+.')->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('single_board')
                            ->defaultFalse()
                            ->info('One board per tenant: the tenant index opens its board, created (with board.default_columns) on the first visit.')
                        ->end()
                        ->scalarNode('resolver')
                            ->defaultNull()
                            ->info('Service id of a Nowo\\TaskBoardBundle\\Tenancy\\TaskBoardTenantResolverInterface (slug => tenant key and name; null for a slug the user may not open). null = the slug is the tenant.')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('assignee_provider')
                    ->defaultNull()
                    ->info('Service id of a Nowo\\TaskBoardBundle\\Assignee\\TaskAssigneeProviderInterface (since 1.10): who may be a member of a task on a board. null = every user of user_class.')
                ->end()
                ->arrayNode('board')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('default_columns')
                            ->info('Columns of a new board, in order (the last one is "done"). Since 1.10.')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('color')->defaultNull()->end()
                                    ->scalarNode('icon')->defaultNull()->info('Icon name such as tabler:bug (since 1.11).')->end()
                                ->end()
                            ->end()
                            ->requiresAtLeastOneElement()
                            ->defaultValue([
                                ['name' => 'To do', 'color' => '#94a3b8'],
                                ['name' => 'In progress', 'color' => '#3b82f6'],
                                ['name' => 'Done', 'color' => '#22c55e'],
                            ])
                        ->end()
                        ->arrayNode('column_icons')
                            ->info('Icons offered in the add / edit column dialogs (since 1.11), as <set>:<name> (e.g. tabler:bug) for UiKit components/_icon (ux_icon) or the Tabler webfont. Empty: no icon field.')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $icon): bool => !is_string($icon) || preg_match(self::ICON_PATTERN, $icon) !== 1)
                                    ->thenInvalid('A column icon is "<set>:<name>" in lowercase letters, digits and dashes, e.g. tabler:bug: %s')
                                ->end()
                            ->end()
                            ->defaultValue(self::DEFAULT_COLUMN_ICONS)
                        ->end()
                        ->booleanNode('top_level_only')
                            ->defaultFalse()
                            ->info('The Kanban board shows top-level tasks only (subtasks are counted on their parent card). Since 1.10.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
