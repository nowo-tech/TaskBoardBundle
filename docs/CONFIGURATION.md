# Configuration

## Table of contents

- [Required](#required)
- [Database](#database)
- [Routes](#routes)
  - [Why a route loader instead of `#[Route]` attributes (REQ-SF-004)](#why-a-route-loader-instead-of-route-attributes-req-sf-004)
- [Security](#security)
  - [Firewall](#firewall)
  - [Access checker](#access-checker)
  - [Per-board / per-task authorization](#per-board--per-task-authorization)
  - [Team membership resolver](#team-membership-resolver)
  - [Access events](#access-events)
- [Templates](#templates)
- [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
  - [CSS framework](#css-framework)
  - [FormKit profile (REQ-TWIG-005)](#formkit-profile-req-twig-005)
- [Other options](#other-options)
  - [Tenancy, boards and assignees (since 1.10)](#tenancy-boards-and-assignees-since-110)
  - [Comments (since 1.16)](#comments-since-116)
  - [Gantt (since 1.15)](#gantt-since-115)
  - [Query budget (since 1.21)](#query-budget-since-121)
  - [Logging (since 1.21)](#logging-since-121)
  - [Rich description editor (Tiptap profile `task`)](#rich-description-editor-tiptap-profile-task)
- [TimeTrack integration (optional)](#timetrack-integration-optional)
- [Task import](#task-import)
  - [Assignee resolver](#assignee-resolver)
  - [Custom importers](#custom-importers)
- [Provider sync (since 1.23)](#provider-sync-since-123)
- [Assets](#assets)

## Required

| Option | Description |
|--------|-------------|
| `user_class` | FQCN of your User entity (`UserInterface` + `getId()`). Used for creator/assignee relations. |

## Database

| Option | Default | Description |
|--------|---------|-------------|
| `table_prefix` | `task_board_` | Prefix for Doctrine table names. |
| `database.entity_manager` | `default` | Doctrine ORM entity manager name. |

Tables created by the bundle (with default prefix):

| Table | Entity |
|-------|--------|
| `task_board_boards` | `TaskBoard` |
| `task_board_columns` | `BoardColumn` |
| `task_board_tasks` | `Task` |
| `task_board_teams` | `Team` |
| `task_board_team_members` | `TeamMember` |
| `task_board_task_members` | `TaskMember` |
| `task_board_task_links` | `TaskLink` |
| `task_board_task_dependencies` | `TaskDependency` |
| `task_board_task_documents` | `TaskDocument` |
| `task_board_task_change_history` | `TaskChangeHistory` |
| `task_board_task_time_entries` | `TaskTimeEntry` |
| `task_board_column_sets` | `ColumnSet` (since 1.11) |

Example:

```yaml
nowo_task_board:
    user_class: App\Entity\User
    table_prefix: task_board_
    database:
        entity_manager: default
```

## Routes

Each route has `path` and `name`. Optional `route_prefix` is prepended to every path (e.g. `/admin`).

| Key | Default path | Default name |
|-----|--------------|--------------|
| `routes.index` | `/tools/task-board` | `nowo_task_board_index` |
| `routes.board` | `/tools/task-board/board/{boardId}` | `nowo_task_board_board` |
| `routes.list` | `/tools/task-board/board/{boardId}/list` | `nowo_task_board_list` |
| `routes.gantt` | `/tools/task-board/board/{boardId}/gantt` | `nowo_task_board_gantt` |
| `routes.task` | `/tools/task-board/task/{taskId}` | `nowo_task_board_task` |
| `routes.board_create` | `/tools/task-board/create` | `nowo_task_board_board_create` |
| `routes.task_create` | `/tools/task-board/board/{boardId}/create` | `nowo_task_board_task_create` |
| `routes.task_move` | `/tools/task-board/task/{taskId}/move` | `nowo_task_board_task_move` |
| `routes.task_advance` | `/tools/task-board/task/{taskId}/advance` | `nowo_task_board_task_advance` |
| `routes.task_link` | `/tools/task-board/task/{taskId}/link` | `nowo_task_board_task_link` |
| `routes.task_link_update` | `/tools/task-board/task/{taskId}/link/{linkId}` | `nowo_task_board_task_link_update` |
| `routes.task_link_remove` | `/tools/task-board/task/{taskId}/link/{linkId}/remove` | `nowo_task_board_task_link_remove` |
| `routes.task_member` | `/tools/task-board/task/{taskId}/member` | `nowo_task_board_task_member` |
| `routes.task_member_remove` | `/tools/task-board/task/{taskId}/member/{memberId}/remove` | `nowo_task_board_task_member_remove` |
| `routes.task_subtask` | `/tools/task-board/task/{taskId}/subtask` | `nowo_task_board_task_subtask` |
| `routes.task_priority` | `/tools/task-board/task/{taskId}/priority` | `nowo_task_board_task_priority` |
| `routes.column_create` | `/tools/task-board/board/{boardId}/column` | `nowo_task_board_column_create` |
| `routes.column_update` | `/tools/task-board/board/{boardId}/column/{columnId}` | `nowo_task_board_column_update` |
| `routes.column_reorder` | `/tools/task-board/board/{boardId}/columns/reorder` | `nowo_task_board_column_reorder` |
| `routes.board_import` | `/tools/task-board/board/{boardId}/import` | `nowo_task_board_board_import` |
| `routes.column_delete` (1.11) | `/tools/task-board/board/{boardId}/column/{columnId}/delete` | `nowo_task_board_column_delete` |
| `routes.column_set_create` (1.11) | `/tools/task-board/board/{boardId}/column-sets` | `nowo_task_board_column_set_create` |
| `routes.column_set_apply` (1.11) | `/tools/task-board/board/{boardId}/column-sets/apply` | `nowo_task_board_column_set_apply` |
| `routes.column_set_delete` (1.11) | `/tools/task-board/board/{boardId}/column-sets/{setId}/delete` | `nowo_task_board_column_set_delete` |
| `routes.task_panel` (1.11, GET) | `/tools/task-board/task/{taskId}/panel` | `nowo_task_board_task_panel` |
| `routes.task_comment` (1.16) | `/tools/task-board/task/{taskId}/comment` | `nowo_task_board_task_comment` |
| `routes.task_comment_edit` (1.16) | `/tools/task-board/task/{taskId}/comment/{commentId}` | `nowo_task_board_task_comment_edit` |
| `routes.task_comment_delete` (1.16) | `/tools/task-board/task/{taskId}/comment/{commentId}/delete` | `nowo_task_board_task_comment_delete` |
| `routes.task_dependency` (1.15) | `/tools/task-board/task/{taskId}/dependency` | `nowo_task_board_task_dependency` |
| `routes.task_import` (1.18, POST) | `/tools/task-board/task/{taskId}/import` | `nowo_task_board_task_import` |
| `routes.task_dependency_remove` (1.15) | `/tools/task-board/task/{taskId}/dependency/{dependencyId}/remove` | `nowo_task_board_task_dependency_remove` |
| `routes.task_time` (1.15) | `/tools/task-board/task/{taskId}/time` | `nowo_task_board_task_time` |
| `routes.task_time_remove` (1.15) | `/tools/task-board/task/{taskId}/time/{entryId}/remove` | `nowo_task_board_task_time_remove` |

Renaming a route (`routes.<key>.name`) is fully supported: the bundle templates never hard-code route names, they resolve them with the Twig function `nowo_task_board_route('<key>')` (e.g. `path(nowo_task_board_route('board'), {boardId: board.id})`). Use the same function in overridden templates.

Import routes:

```yaml
# config/routes/nowo_task_board.yaml
nowo_task_board:
    resource: .
    type: nowo_task_board
```

### Why a route loader instead of `#[Route]` attributes (REQ-SF-004)

REQ-SF-004 exemption, recorded on purpose: the manage routes are built by `Routing\TaskBoardRouteLoader` (`type: nowo_task_board`)
from this configuration, not declared with `#[Route]` attributes on `TaskBoardManageController`.

- **Every route name and path is configuration** (`routes.<key>.name`, `routes.<key>.path`, `route_prefix`). Hosts
  rename them (Beacon serves them under its own paths) and generate them by name. An attribute fixes one name and one
  path per action at compile time, so attributes could not keep these keys without a BC break (REQ-SF-004 rule 5,
  REQ-BC-001).
- **Tenancy copies every route** once per `tenancy.tenant_paths` entry (`<name_prefix><name>`, `{tenantSlug}` in the
  path, a slug requirement per entry). Attribute routing has no equivalent of a per-host list of copies.
- **Routes stay opt-in (REQ-SF-004 rule 7).** The controller carries no `#[Route]`, so `routing.controllers` (Symfony
  >= 7.3 autoconfiguration) never loads it; only the documented `type: nowo_task_board` import does.
  `tests/Unit/Routing/HostRoutingControllersTest` boots a kernel whose only routing is `routing.controllers` and
  asserts that no bundle route exists.
- **One loader, no YAML route files** (REQ-SF-004 rule 3): the bundle ships no `routing.yaml`/XML; the loader maps
  each route key to its controller action and HTTP methods in one place (`TaskBoardRouteLoader::load()`).

Route names, paths, methods and the prefix are unchanged by 1.21.

## Security

### Firewall

Manage routes require an authenticated user on your main firewall:

```yaml
# config/packages/security.yaml (example)
security:
    access_control:
        - { path: ^/tools/task-board, roles: ROLE_USER }
```

### Access checker

Replace the default role-based checker with your own service implementing `TaskBoardAccessCheckerInterface`:

```yaml
nowo_task_board:
    security:
        access_checker: App\Security\TeamTaskBoardAccessChecker
```

Default role configuration:

| Option | Default | Purpose |
|--------|---------|---------|
| `security.access_checker` | `null` | Custom `TaskBoardAccessCheckerInterface` service id (since 1.24 a `TenantAwareTaskBoardAccessCheckerInterface` also gets the tenant key in `canCreateBoard()`) |
| `security.access_roles` | `[ROLE_USER]` | Open manage UI (hosts may set `ROLE_ADMIN` for admin-only) |
| `security.create_roles` | `[ROLE_USER]` | Create boards |
| `security.list_roles` | `[ROLE_USER]` | List boards |
| `security.resource_scoping` | `true` | Per-board/per-task authorization (see below). `false` restores the legacy "every user sees every board" behaviour — not recommended |
| `security.manage_roles` | `[ROLE_ADMIN]` | Roles that can view and edit **every** board regardless of ownership or team |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only — wires AllowAll checker and disables resource scoping; production MUST keep `false` |

When `allow_unauthenticated` is `false`, `symfony/security-bundle` is required (compile-time `LogicException` otherwise).

### Per-board / per-task authorization

Every manage action that loads a board or task by id (board, list, Gantt, import, columns, tasks, links, members, subtasks) goes through `TaskBoardResourceAccessChecker`:

- **Board** — visible/editable by its creator, by members of the board's team (`TeamMember` rows or the `team_membership_resolver`), and by users with one of `security.manage_roles`.
- **Task** — board access as above, plus the task creator and any user attached to the task as a member (assignee, reviewer…).
- The board index only lists boards the user can access (and is empty when the user lacks `security.list_roles`).
- Unknown ids return **404**; existing boards/tasks the user cannot access return **403**.

Listeners on `BOARD_ACCESS_CHECK` / `TASK_ACCESS_CHECK` can override the default decision (see [Access events](#access-events)).

### Team membership resolver

Optional service implementing `TaskBoardTeamMembershipResolverInterface` for team-aware ACL:

```yaml
nowo_task_board:
    team_membership_resolver: App\Security\TeamMembershipResolver
```

When not configured, a null resolver is used and `TaskAccessGuard` falls back to assignee/team membership checks via repositories.

A board's team (`TaskBoard::getTeam()`) is what both checks compare against. The manage UI has no team picker: assign it in your own code (`$board->setTeam($team)` and persist), e.g. from an admin form, a fixture or a listener on board creation. Boards without a team are only visible to their creator and `security.manage_roles`; task creators and task members can still open (and edit) their own tasks, but not the board.

### Access events

Events dispatched by `TaskBoardResourceAccessChecker` (requires the `event_dispatcher` service):

| Event | Constant | Purpose |
|-------|----------|---------|
| `BoardListQueryEvent` | `TaskBoardEvents::BOARD_LIST_QUERY` | Replace the candidate board list on the index (`overrideList()`); the result is still filtered by board access |
| `BoardAccessCheckEvent` | `TaskBoardEvents::BOARD_ACCESS_CHECK` | `grant()` / `deny()` board access; `isDefaultGranted()` exposes the built-in decision |
| `TaskAccessCheckEvent` | `TaskBoardEvents::TASK_ACCESS_CHECK` | `grant()` / `deny()` task access; `markReadOnly()` blocks every mutation on the task (403) while keeping it viewable |
| `ColumnSetQueryEvent` (1.11) | `TaskBoardEvents::COLUMN_SET_QUERY` | Scope of the column sets listed and saved (`setScopeKey()`; default the board's tenant key, null without tenancy) or `overrideList()`; dispatched by `ColumnSetManager` |
| `ColumnSetAccessCheckEvent` (1.11) | `TaskBoardEvents::COLUMN_SET_ACCESS_CHECK` | `grant()` / `deny()` deleting a column set; default: its creator or a `security.manage_roles` role |

`deny()` always wins over the built-in rules; `grant()` opens a board/task the built-in rules would refuse.

The constants `BOARD_LIST_RESULT`, `TASK_LIST_QUERY`, `TASK_LIST_RESULT`, `TASK_READ_ONLY_RESOLVE` and `MEMBER_LIST_QUERY` are **reserved** and not dispatched yet (no event classes exist for them); use `TaskAccessCheckEvent::markReadOnly()` for read-only tasks.

```php
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: TaskBoardEvents::TASK_ACCESS_CHECK)]
final class MyTaskAccessListener
{
    public function __invoke(\Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent $event): void
    {
        // grant() or deny() based on your rules
    }
}
```

## Templates

Override via `templates/bundles/NowoTaskBoardBundle/` or config:

```yaml
nowo_task_board:
    templates:
        layout: '@NowoTaskBoardBundle/layout.html.twig'  # see Layout integration below
        css_framework: tabler                            # see CSS framework below
        index: '@NowoTaskBoardBundle/manage/index.html.twig'
        board: '@NowoTaskBoardBundle/manage/board.html.twig'
        list: '@NowoTaskBoardBundle/manage/list.html.twig'
        gantt: '@NowoTaskBoardBundle/manage/gantt.html.twig'
        task: '@NowoTaskBoardBundle/manage/task.html.twig'
        import: '@NowoTaskBoardBundle/manage/import.html.twig'
        task_panel: '@NowoTaskBoardBundle/manage/task_panel.html.twig'   # since 1.11, no layout
        filters_collapse: null       # since 1.17, host partial folding the list filters
        filters_form_class: ''       # since 1.17, extra classes on the list filter form
```

| Option | Default | Description |
|--------|---------|-------------|
| `templates.layout` | `@NowoTaskBoardBundle/layout.html.twig` | Twig layout extended by manage pages (Twig global `nowo_task_board_layout`). **Host apps set this to the project layout** (or a one-file bridge). Default is the bundle demo layout only. |
| `templates.css_framework` | `tabler` | Host CSS stack hint (Twig global `nowo_task_board_css_framework`). Values: `bootstrap5`, `bootstrap` (alias), `bootstrap4`, `tabler` (Bootstrap-compatible), `tailwind`, `foundation`, `custom`, `none`. Default matches the demo Tabler CDN. Invalid values are rejected. |
| `templates.index` | `@NowoTaskBoardBundle/manage/index.html.twig` | Boards index page. |
| `templates.board` | `@NowoTaskBoardBundle/manage/board.html.twig` | Kanban board page. |
| `templates.list` | `@NowoTaskBoardBundle/manage/list.html.twig` | List view page. |
| `templates.gantt` | `@NowoTaskBoardBundle/manage/gantt.html.twig` | Gantt view page. |
| `templates.task` | `@NowoTaskBoardBundle/manage/task.html.twig` | Task detail page. |
| `templates.import` | `@NowoTaskBoardBundle/manage/import.html.twig` | Import page. |
| `templates.task_panel` | `@NowoTaskBoardBundle/manage/task_panel.html.twig` | Task editing panel without layout (since 1.11), for side drawers. |
| `templates.filters_collapse` | `null` | Since 1.17: host partial that folds the list view filters (e.g. `@NowoUiKitBundle/partials/_filters_collapse.html.twig`), included first in the filter form with `key` / `fold_key` and `form` / `filter_form`. Null keeps the bundle's "Filters" toggle. |
| `templates.filters_form_class` | `''` | Since 1.17: extra classes on the list view filter form (the host's filter card). |

## Layout integration (REQ-UI-001)

Manage pages `{% extends nowo_task_board_layout %}` (Twig global from `templates.layout`) and call `{{ parent() }}` in `stylesheets` / `javascripts` so host and bundle assets stack.

**Set `templates.layout` and `templates.css_framework`** so TaskBoard renders inside your admin shell with the right CSS stack — do not fork every manage page:

```yaml
nowo_task_board:
    templates:
        layout: 'base.html.twig'   # project chrome (header, sidebar, flash area)
        css_framework: bootstrap5  # or tabler | custom | …
```

| Piece | Role |
|-------|------|
| Twig global `nowo_task_board_layout` | Value of `templates.layout` |
| Twig global `nowo_task_board_css_framework` | Value of `templates.css_framework` |
| `@NowoTaskBoardBundle/layout.html.twig` | Demo full-HTML layout (Tabler CDN + navbar when `css_framework` is `tabler` / Bootstrap). Used only when `templates.layout` keeps the default. |

### CSS framework

| Value | Behaviour |
|-------|-----------|
| `tabler` (default) | Same look as the demo. Manage markup uses Tabler / Bootstrap 5-compatible classes and Tabler Icons (`ti ti-*`). |
| `bootstrap5` / `bootstrap` / `bootstrap4` | Treat like Bootstrap; existing page classes remain compatible. Host (or demo layout) provides Bootstrap/Tabler CSS. |
| `tailwind` / `foundation` | Accepted for host alignment; pages still emit Bootstrap/Tabler classes until macros exist — provide compatible CSS or map via your layout. |
| `custom` / `none` | Rely on semantic `nowo-ui-*` classes and **host CSS**. Do not expect Bootstrap utility classes to style the UI. |

When you point `templates.layout` at the project, the demo Tabler CDN is skipped; your stack must provide styles matching `css_framework`. Bundle CSS/JS (`task-board.css` / `task-board.js`) are always loaded from manage pages via `parent()` stacking.

Your project layout (or bridge) **must define** `stylesheets` and `javascripts` blocks so `{{ parent() }}` can stack assets.

**Content blocks:** pages fill `body`. The demo layout also exposes `nowo_ui_content` nested under `body` for bridges. If your project layout uses a different content block name, add a thin bridge (see `@NowoTaskBoardBundle/layout_integrate_host.html.twig`) and set `templates.layout` to that bridge:

```yaml
nowo_task_board:
    templates:
        layout: 'platform/admin/task_board_bridge.html.twig'
```

Example bridge:

```twig
{% extends 'base.html.twig' %}

{% block body %}
    {% block nowo_ui_content %}{% endblock %}
{% endblock %}

{% block stylesheets %}
    {{ parent() }}
{% endblock %}

{% block javascripts %}
    {{ parent() }}
{% endblock %}
```

### FormKit profile (REQ-TWIG-005)

Every manage form (task, board, column, column sets, links, members, import, priority, time entry, dependency,
comment, list filter, CSRF-only actions) is a Symfony Form Type built with FormKit (`#[FormKitConfig('task_board')]`),
rendered with `form_start` / the child `form_row` loop / `form_end` — never raw `<form>` / `<input>` (keep it that way
in overrides). Fields added through FormKit take their options from the profile `nowo_form_kit.profiles.task_board`,
which the extension prepends when FormKitBundle is registered and the host does not define it (translation domain
`NowoTaskBoardBundle`, `nowo-ui-input form-control` text controls, `form-select` choices, `form-check` checkboxes).
Define `profiles.task_board` in your own `nowo_form_kit` config to theme the forms; the bundle then does not prepend its
default. The priority, time entry, dependency, comment and list filter forms keep their explicit classes and Stimulus
hooks (the profile does not add classes or convention keys to them).

## Other options

| Option | Default | Description |
|--------|---------|-------------|
| `route_prefix` | `''` | Prepended to all route paths |
| `dashboard_route` | `null` | Route name for "back" link in manage UI |
| `firewall` | `main` | **Deprecated since 1.7, no effect.** Never read by the bundle; protect the manage routes in your `security.yaml` (see [Firewall](#firewall)). Remove the key |

### Tenancy, boards and assignees (since 1.10)

All optional; the defaults keep the 1.9 behaviour. See [Multi-tenant hosts](USAGE.md#multi-tenant-hosts-since-110).

| Option | Default | Description |
|--------|---------|-------------|
| `tenancy.enabled` | `false` | Boards per host tenant: manage routes copied under `tenancy.tenant_paths`, router decorated, boards / tasks of another tenant answer 404. Needs at least one `tenant_paths` entry |
| `tenancy.tenant_paths[].path` | — (required) | Base path of the copies; must hold `{tenantSlug}` (e.g. `/projects/{tenantSlug}/tasks`). Replaces `routes.index.path` in every route path |
| `tenancy.tenant_paths[].name_prefix` | — (required) | Prefix of the copied route names (`app_project_` → `app_project_nowo_task_board_board`) |
| `tenancy.tenant_paths[].slug_prefix` | `''` | Slugs this entry serves (prefix match, first entry wins); `''` = every slug |
| `tenancy.tenant_paths[].slug_requirement` | `null` | Route requirement of `{tenantSlug}`; `null` = the slug prefix then `[A-Za-z0-9_-]+` |
| `tenancy.single_board` | `false` | One board per tenant: the tenant index opens it (created with `board.default_columns` and the tenant label as name on the first visit) |
| `tenancy.resolver` | `null` | Service id of a `TaskBoardTenantResolverInterface` (slug → tenant, or null = 404). `null` = the slug is the tenant |
| `assignee_provider` | `null` | Service id of a `TaskAssigneeProviderInterface`: who may be a member of a task on a board. `null` = every `user_class` user |
| `board.default_columns` | To do, In progress, Done | Columns of a new board (`name`, optional `color`, optional `icon` since 1.11), in order; the last one is "done" |
| `board.column_icons` | 30 Tabler icons (`Configuration::DEFAULT_COLUMN_ICONS`) | Since 1.11: icons offered in the add / edit column dialogs, as `<set>:<name>` (e.g. `tabler:bug`), rendered through UiKit `components/_icon` (`ux_icon()` with `icon_set: ux_icon`, the bare name with the webfont sets). `[]` = no icon field |
| `board.top_level_only` | `false` | The Kanban board shows top-level tasks only, with a done / total subtask counter on the card |
| `board.max_tasks` | `500` | Since 1.21 ([REQ-PERF-001](#query-budget-since-121)): the most tasks the board, list, Gantt and task pages load per board |
| `board.default_view_resolver` | `null` | Since 1.24: service id of a `TaskBoardDefaultViewResolverInterface` (the view a board opens in for a user: `board`, `list` or `gantt`). The plain board route redirects to the list / Gantt route; `?view=board`, XHR and links from the same board keep the board view. `null` = always the board view |
| `board.create_dialog_header` | `null` | Since 1.24: request header of the host's dialogs (e.g. `X-Beacon-Dialog`). An invalid *New board* carrying it answers `422` with only `manage/_board_create_form.html.twig`; without it, `422` with the index page and the dialog open |

Host object link resolvers (`TaskObjectLinkResolverInterface`) need no option: they are autoconfigured.

### Query budget (since 1.21)

REQ-PERF-001: a page never loads an unbounded number of tasks.

| Page | Query | Bound |
|------|-------|-------|
| Board, list, Gantt | The board's tasks in position order (the first cards of every column), then their members and the members' users in one query | `board.max_tasks` (default **500**); above it the page says "Showing N of M tasks" (`manage/_task_window_notice.html.twig`, variable `taskWindow`) and counts the board's tasks with one `COUNT` query |
| Task page and panel | The task with board, column and parent, then one query per relation the page shows (members + users, links, documents, dependencies + their tasks, history + users, time entries); navigation, subtasks and dependency choices reuse the bounded board tasks | Fixed number of queries per task |
| Comments | One query per task (authors fetched with them); the board and list counters are one grouped query | — |

A host that aliases `TaskRepositoryInterface` to its own repository without `BoundedTaskRepositoryInterface` keeps the
1.20 behaviour (every task of the board).

```yaml
nowo_task_board:
    board:
        max_tasks: 1000   # very large boards; keep it bounded
```

### Logging (since 1.21)

REQ-OBS-001: the bundle logs through the autowired `Psr\Log\LoggerInterface` (a `NullLogger` when the container has
none). Route it with Monolog like any `app` log:

| Level | Message | Context |
|-------|---------|---------|
| info | `TaskBoard write: {action}` | `bundle`, `action` (route key, e.g. `task_move`), the ids of the board objects (`board_id`, `task_id`, `column_id`, …), counts |
| warning | `TaskBoard write refused: {reason}` | `bundle`, `reason` (`csrf_invalid`), `route` |
| info / warning | `TaskBoard import started` / `finished` / `refused` | `bundle`, `board_id`, `task_id`, `source`, `bytes`, created / skipped / columns / dependencies / warnings / errors counts |
| error | `TaskBoard import failed` | same, plus the exception class |

Never logged: user ids, names or e-mails, task titles, descriptions, comments, link URLs, uploaded file names or
contents, CSRF tokens. See [SECURITY.md](SECURITY.md#logging-and-personal-data).

### Comments (since 1.16)

| Option | Default | Description |
|--------|---------|-------------|
| `comments.readers_can_comment` | `true` | People who may read a task but not edit it (read-only boards) may comment, like ClickUp guests with comment rights. `false` = editors only |
| `comments.mention_resolver` | `null` | Service id of a `Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface`: who may be @mentioned (the people who can read the task). `null` = the board's assignable users, else its creator and task members |

### Gantt (since 1.15)

| Option | Default | Description |
|--------|---------|-------------|
| `gantt.week_start` | `monday` | First day of the week (`monday` or `sunday`): the columns of the Weeks scale and the week separators of the Days scale |
| `gantt.week_start_resolver` | `null` | Service id of a `Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface` answering the week start per request (e.g. a user preference). `null` = `gantt.week_start` |

```yaml
nowo_task_board:
    gantt:
        week_start: sunday                                  # every user
        week_start_resolver: App\Task\UserWeekStartResolver  # or per user
```

See [Gantt scales and week start](USAGE.md#gantt-scales-and-week-start).

### Rich description editor (Tiptap profile `task`)

When `nowo-tech/tiptap-editor-bundle` is installed, `TaskFormType` renders the description with the Tiptap profile **`task`**. The bundle seeds that profile automatically (prepend) unless your app defines `nowo_tiptap_editor.profiles.task` itself:

```yaml
# Seeded by TaskBoardBundle — no host config needed
nowo_tiptap_editor:
    profiles:
        task:
            variant: simple
            toolbar: true
            min_height: 220px
            form_theme: <your default profile's form_theme, when set>
```

To customise it, declare the profile in `config/packages/nowo_tiptap_editor.yaml` (your definition wins and nothing is seeded):

```yaml
nowo_tiptap_editor:
    profiles:
        default:
            form_theme: bootstrap_5_layout.html.twig
        task:
            variant: notion
            min_height: 320px
            form_theme: bootstrap_5_layout.html.twig
```

Without the Tiptap bundle the description falls back to a plain `<textarea>`.

## TimeTrack integration (optional)

When [TimeTrack Bundle](https://github.com/nowo-tech/TimeTrackBundle) is installed, the extension registers these service aliases:

| Alias | Service |
|-------|---------|
| `nowo_task_board.task_provider` | `TaskBoardTaskProvider` |
| `nowo_task_board.team_context_provider` | `TaskBoardTeamContextProvider` |

Wire them in `nowo_time_track` config — see [INSTALLATION.md](INSTALLATION.md).

If TimeTrack is not installed, no aliases or bridge services are registered.

See [Import export guides](import/README.md) for step-by-step ClickUp, Jira, and Trello export instructions.

## Task import

Import tasks from ClickUp, Jira, or Trello exports. Route defaults:

| Key | Default path | Default name |
|-----|--------------|--------------|
| `routes.board_import` | `/tools/task-board/board/{boardId}/import` | `nowo_task_board_board_import` |

Template override: `nowo_task_board.templates.import` (default `@NowoTaskBoardBundle/manage/import.html.twig`).

### Assignee resolver

By default, `NullTaskImportUserResolver` ignores assignee emails. Replace the alias to map emails to users:

```yaml
services:
    App\TaskBoard\ImportUserResolver: ~

    Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface:
        alias: App\TaskBoard\ImportUserResolver
```

### Custom importers

Implement `TaskImporterInterface` and tag the service:

```yaml
services:
    App\TaskBoard\CustomImporter:
        tags: [{ name: nowo_task_board.task_importer }]
```

Since 1.22 every import is an idempotent upsert ([Re-importing](import/README.md#re-importing-idempotent-upsert-since-122)).
A custom importer must return the same `ImportedTaskDto`s for the same file (read dates with
`ImportFieldMapper::parseDate()`, never "now"), leave a field null / empty when the source has no value (a re-import
then keeps the task's value), and set `hasPriority: false` when the row has no priority. Its rows are matched under the
scope of the `TaskImportSource` it supports.

### Assignees by name (since 1.22)

Rows that name assignees instead of giving an e-mail (ClickUp, Jira, Trello, the own format) are resolved through
`assignee_provider` (`TaskAssigneeProviderInterface::assignableUsers()` of the board): the one user whose display name
(`UserLabel::of()`: `displayName`, `fullName`, e-mail, username, identifier) equals the name, ignoring case and
spaces. Without an `assignee_provider`, names are not resolved and the import warns.

### Import limits (since 1.22)

```yaml
nowo_task_board:
    import:
        max_rows: 5000          # rows (tasks) per file; a larger file is refused
        max_cell_length: 65536  # bytes per CSV cell; a file with a longer cell is refused
        max_columns: 512        # since 1.22.1: columns of a CSV header or row; a wider file is refused
        max_cells: 1000000      # since 1.22.1: cells of a CSV file (columns × rows, empty ones too); a larger file is refused
        max_json_nodes: 4000000 # since 1.22.1: weight of a JSON file before decoding (about 16 bytes of peak decoded memory per unit)
```

Every limit is checked before anything is built (CSV: one pass over the raw bytes; JSON: before `json_decode()`, and
the items again before they are mapped). Blank CSV lines count as rows. Raise the limits together with PHP's
`memory_limit`. The errors are translated
(`task_board.import.error.*`).

Measured through the import controller with a production kernel (no query logging) and an 18 MB request baseline,
an import keeps a flat memory since 1.22.2 (tasks are written in batches of 200), and a `memory_limit` of 128 MB takes:

- **CSV:** 5 000 rows, the default `max_rows` (peak +32 MB);
- **Trello JSON:** 5 000 cards with their checklists and no actions (≈ 5.7 MB, weight ≈ 3.1 M; peak +68 MB);
  a board export with its actions (Trello adds the last 1 000 by default) weighs more: 5 000 cards plus 1 000 actions weigh about 4.3 M and are refused cleanly; export without actions, or set `max_json_nodes: 4500000` with a `memory_limit` of 256 MB;
- **the bundle's JSON:** 5 000 tasks;
- **ClickUp API JSON:** about 2 700 tasks (≈ 5.7 MB; peak +61 MB). A 5 000-task export (10.5 MB, weight ≈ 7.4 M)
  is refused cleanly by the default weight: decoding it alone needs more than 128 MB. Import it with
  `max_json_nodes: 7500000` and a `memory_limit` of 256 MB.

The default `max_json_nodes` (4 000 000) keeps every hostile JSON shape the audits tried under +70 MB at that limit.
A host whose requests already use more than about 55 MB before the import should lower `max_json_nodes` (to about 2 500 000) or raise `memory_limit`.

- **Jira "all fields" CSV exports** (about 250–300 columns × 5 000 rows = 1.25–1.5 M cells): `max_cells: 2000000`
  (and `max_columns: 1024` past 512 columns), or export fewer columns; a kept cell costs about 70 bytes, so raise
  `memory_limit` with it.

An import runs in one database transaction: if it fails anywhere, nothing of it is kept. Two imports into the same board at the same time can each create a task for the same source id (they do not lock the board; this was already so before 1.22.2): import one file at a time.

See [UPGRADING](UPGRADING.md#unreleased) for the measurements.

### Cross-board lookup (since 1.22)

`Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface` (default `DoctrineTaskImportLinkLookup`) tells the import
which source ids (and fingerprints of rows without an id) are already imported into another board of the same
tenant; those rows are skipped. The bundle's own format is not checked. Alias it to
your own service to change the rule (return `[]` to allow copies across boards).

## Provider sync (since 1.23)

Opt-in: with `enabled: false` (the default) there are no sync routes, services, Doctrine mapping or header change. See
[sync.md](sync.md).

```yaml
nowo_task_board:
    sync:
        enabled: false
        credential_resolver: ~        # REQUIRED when enabled: ProviderCredentialResolverInterface service id
        attachment_storage: ~         # TaskAttachmentStorageInterface; null = files refused (storage_disabled)
        inline_image_rewriter: ~      # InlineImageRewriterInterface; null = MapInlineImageRewriter
        limit_checker: ~              # SyncLimitCheckerInterface (host quotas); null = none
        runner: ~                     # SyncRunnerInterface (e.g. Messenger); null = InlineSyncRunner
        actor_resolver: ~             # SyncActorResolverInterface; null = request user, else who linked the board (disabled users refused, 1.24)
        actor_checker: ~              # since 1.24: UserCheckerInterface run with checkPreAuth() on the default resolver's actor
        providers:
            clickup:
                enabled: true
                attachment_hosts: ['*.clickup-attachments.com', 'attachments.clickup.com', 'attachments2.clickup.com']
        http:
            client: ~                 # an untraced HttpClientInterface service (proxy); null = the bundle's own client
            timeout: 10               # idle seconds per API call
            max_duration: 30          # seconds per API call
            max_response_bytes: 8388608
            max_inline_wait: 30       # a longer rate-limit wait pauses the run (rate_limited, resumable)
        attachments:
            enabled: true
            max_bytes: 26214400       # per file
            max_bytes_per_run: 1073741824
            max_redirects: 3
            allowed_mime_types: [image/png, image/jpeg, image/gif, image/webp, application/pdf, text/plain, text/csv, …]
        max_tasks_per_run: 5000       # reaching it pauses the slice (resumable)
        max_pages_per_run: 1000       # since audit EG-L2: task pages of one run over all its slices; past it the run fails
        max_comments_per_task: 500
        max_description_length: 100000
        max_archive_ratio: 0.5        # the mass-archive guard
        history_keep: 50              # runs kept per board
        container_cache_ttl: 300      # seconds a level of the container browser is cached (cache.app)
        inline_slice_pages: 5         # task pages the inline runner writes per request
        progress:
            poll_interval_ms: 2000
        badge:
            connect_link: true        # since 1.25.2: false = an unlinked board shows no badge (no "Connect provider" link)
            progress: false           # since 1.26.3: true = the bundle's badge shows a determinate bar of the running run
        custom_fields:
            mode: typed               # since 1.27.0: typed (field definitions + typed values) | text (1.23 "Fields" document) | both
        import_archived: true         # since 1.27.0: archived provider tasks are read and imported archived (false = 1.26)
        status_history:
            max_calls_per_run: 200    # since 1.27.0: "time in status" requests per run for the tasks it writes (0 = never)
        # Since 1.25 (ClickUp full import; see sync.md):
        list_as_tag: false            # tag "list:<name>" on tasks of a folder / space source
        lookup:
            cache_pool: ~             # PSR-6 pool for the list search (300 s, keyed by a hash of the connection ref); null = no cache
        people:
            resolver: ~               # SyncPersonResolverInterface; null = DefaultSyncPersonResolver (1.23 e-mail semantics, one directory per board)
            require_verified_email: true   # false = an unverified e-mail match is applied as `auto` (1.23 parity)
            reassign_batch_size: 500  # 50–2000
            # Since 1.27.0 (TB-SYNC-2): the People page / step (see sync.md "People page and step")
            step: false               # true = linking scans the people and opens the People page; its save starts the import
            inviter: ~                # PeopleInviterInterface ("Invite by e-mail"); null = no invite option
            page_size: 50             # people per page (10–200)
            candidate_limit: 200      # host users offered per select (1–1000)
            reapply_seconds: 10       # seconds a save (and the end of each sync) spends re-applying changed mappings (0–60)
            scan:
                max_task_pages: 1     # 1–5
                max_comment_tasks: 10 # 0–50
                time_window: true     # one 30-day window in the pre-scan with an admin token
        watchers:
            enabled: true
            max_detail_calls_per_run: 500   # GET task/{id} (E5) for rows without watchers / checklists
        checklists:
            enabled: true
            max_items_per_run: 2000   # new checklist subtasks per run (also asked from sync.limit_checker)
        time_entries:
            sink: ~                   # ImportedTimeEntrySinkInterface; null = the time phase never runs
            window_days: 30           # 1–30
            max_assignees_per_call: 25     # 1–100 (upstream limit TO CONFIRM)
            max_entries_per_window: 10000  # at or above it the window is halved (upstream cap TO CONFIRM)
            max_windows_per_run: 200
            fallback_max_tasks_per_run: 500
            max_calls_per_run: 500    # time-entry requests per slice, splits included (then the run pauses)
```

Since 1.25 the sync tables gain a people directory (schema in [UPGRADING — To 1.25.0](UPGRADING.md#schema-change-with-syncenabled-true-only)).

New routes (configurable like the others, copied under `tenancy.tenant_paths`): `board_connection` (GET),
`board_connection_containers`, `board_connection_link`, `board_connection_refresh`, `board_connection_cancel`,
`board_connection_unlink` (POST, CSRF), `board_connection_progress` (GET, JSON), and `task_restore` (POST, always on).
New templates: `connection`, `connection_containers`, `connection_badge`, `connection_progress`, `connection_history`;
since 1.27.0 `connection_people` (the People page) and `connection_people_summary` (its line on the connection page).
Since 1.27.0 the routes `board_connection_people` (GET) and `board_connection_people_save` (POST, CSRF) are added.

Since 1.25.2, `sync.badge.connect_link: false` hides the board / list / Gantt header badge of an **unlinked** board
(the *Connect provider* link and its strip) for hosts that offer the connection elsewhere (e.g. an import menu linking
`board_connection`); a linked board keeps its badge. Default `true` renders exactly as 1.25.1. No template override is
needed; the bundle partial reads the Twig global `nowo_task_board_sync_badge_connect_link`, which a host's own
`templates.connection_badge` partial may read too.

Since 1.26.3 (TB-SYNC-1) the badge data (`nowo_task_board_connection(board)`) also carries the running run:

| Key | Value |
| --- | --- |
| `activeRunId` | id of the run in progress (running or paused), else `null` |
| `progressUrl` | `board_connection_progress` of that run, **only for users who may manage the connection** (the endpoint answers 404 to the others), else `null` |
| `total` / `processed` | the run's latest counters (`total` is `null` when the provider gave no task count: indeterminate bar) |
| `pollInterval` | `sync.progress.poll_interval_ms` |
| `progress` | `sync.badge.progress` |

A host badge can hand them to the bundle's `nowo-task-board-sync-progress` Stimulus controller (targets `bar`, `status`,
`done`, optional `cancel`; values `url`, `interval`): the bar fills from the polled counters, the polite live region
changes at most every 5 s, and the `done` element is shown when the run ends.

```twig
{% if sync.running and sync.progressUrl %}
    <div data-controller="nowo-task-board-sync-progress"
         data-nowo-task-board-sync-progress-url-value="{{ sync.progressUrl }}"
         data-nowo-task-board-sync-progress-interval-value="{{ sync.pollInterval }}">
        <progress {% if sync.total %}max="{{ sync.total }}" value="{{ min(sync.processed, sync.total) }}"{% endif %} data-nowo-task-board-sync-progress-target="bar"></progress>
        <p role="status" aria-live="polite" aria-atomic="true" data-nowo-task-board-sync-progress-target="status">…</p>
        <a href="{{ path(nowo_task_board_route('board_connection'), {boardId: board.id}) }}" hidden data-nowo-task-board-sync-progress-target="done">Show the result</a>
    </div>
{% endif %}
```

`sync.badge.progress: true` makes the bundle's own `_connection_badge.html.twig` render that bar (the default `false`
renders as 1.26.2).

## Assets

The bundle ships Stimulus controllers built to `src/Resources/public/js/task-board.js` (asset package `nowo_task_board`, installed under `public/bundles/taskboard/`).

Rebuild after changes:

```bash
pnpm install && pnpm run build
```
