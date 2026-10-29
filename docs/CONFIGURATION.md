# Configuration

## Table of contents

- [Required](#required)
- [Database](#database)
- [Routes](#routes)
- [Security](#security)
  - [Firewall](#firewall)
  - [Access checker](#access-checker)
  - [Per-board / per-task authorization](#per-board--per-task-authorization)
  - [Team membership resolver](#team-membership-resolver)
  - [Access events](#access-events)
- [Templates](#templates)
- [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
  - [CSS framework](#css-framework)
- [Other options](#other-options)
  - [Tenancy, boards and assignees (since 1.10)](#tenancy-boards-and-assignees-since-110)
  - [Comments (since 1.16)](#comments-since-116)
  - [Gantt (since 1.15)](#gantt-since-115)
  - [Rich description editor (Tiptap profile `task`)](#rich-description-editor-tiptap-profile-task)
- [TimeTrack integration (optional)](#timetrack-integration-optional)
- [Task import](#task-import)
  - [Assignee resolver](#assignee-resolver)
  - [Custom importers](#custom-importers)
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
| `security.access_checker` | `null` | Custom `TaskBoardAccessCheckerInterface` service id |
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

Host object link resolvers (`TaskObjectLinkResolverInterface`) need no option: they are autoconfigured.

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

## Assets

The bundle ships Stimulus controllers built to `src/Resources/public/js/task-board.js` (asset package `nowo_task_board`, installed under `public/bundles/taskboard/`).

Rebuild after changes:

```bash
pnpm install && pnpm run build
```
