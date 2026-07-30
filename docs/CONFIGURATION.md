# Configuration

## Table of contents

- [Required](#required)
- [Database](#database)
- [Routes](#routes)
- [Security](#security)
  - [Firewall](#firewall)
  - [Access checker](#access-checker)
  - [Team membership resolver](#team-membership-resolver)
  - [Access events](#access-events)
- [Templates](#templates)
- [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
- [Other options](#other-options)
- [TimeTrack integration (optional)](#timetrack-integration-optional)
- [Task import](#task-import)
  - [Assignee resolver](#assignee-resolver)
  - [Custom importers](#custom-importers)
- [Assets](#assets)

All options live under the `nowo_task_board` root key in `config/packages/nowo_task_board.yaml`.

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
| `task_board_board_columns` | `BoardColumn` |
| `task_board_tasks` | `Task` |
| `task_board_teams` | `Team` |
| `task_board_team_members` | `TeamMember` |
| `task_board_task_members` | `TaskMember` |
| `task_board_task_links` | `TaskLink` |
| `task_board_task_dependencies` | `TaskDependency` |
| `task_board_task_documents` | `TaskDocument` |
| `task_board_task_change_history` | `TaskChangeHistory` |
| `task_board_task_time_entries` | `TaskTimeEntry` |

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
| `routes.task_member` | `/tools/task-board/task/{taskId}/member` | `nowo_task_board_task_member` |
| `routes.task_subtask` | `/tools/task-board/task/{taskId}/subtask` | `nowo_task_board_task_subtask` |
| `routes.task_priority` | `/tools/task-board/task/{taskId}/priority` | `nowo_task_board_task_priority` |
| `routes.column_create` | `/tools/task-board/board/{boardId}/column` | `nowo_task_board_column_create` |
| `routes.column_update` | `/tools/task-board/board/{boardId}/column/{columnId}` | `nowo_task_board_column_update` |
| `routes.column_reorder` | `/tools/task-board/board/{boardId}/columns/reorder` | `nowo_task_board_column_reorder` |
| `routes.board_import` | `/tools/task-board/board/{boardId}/import` | `nowo_task_board_board_import` |

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
| `security.access_roles` | `[ROLE_USER]` | Open manage UI |
| `security.create_roles` | `[ROLE_USER]` | Create boards |
| `security.list_roles` | `[ROLE_USER]` | List boards |

### Team membership resolver

Optional service implementing `TaskBoardTeamMembershipResolverInterface` for team-aware ACL:

```yaml
nowo_task_board:
    team_membership_resolver: App\Security\TeamMembershipResolver
```

When not configured, a null resolver is used and `TaskAccessGuard` falls back to assignee/team membership checks via repositories.

### Access events

Extensibility hooks for board/task list filtering and per-task access:

| Event | Constant | Purpose |
|-------|----------|---------|
| `BoardListQueryEvent` | `TaskBoardEvents::BOARD_LIST_QUERY` | Override board list query |
| `BoardListResultEvent` | `TaskBoardEvents::BOARD_LIST_RESULT` | Filter/reorder loaded boards |
| `TaskListQueryEvent` | `TaskBoardEvents::TASK_LIST_QUERY` | Override task list query |
| `TaskListResultEvent` | `TaskBoardEvents::TASK_LIST_RESULT` | Filter/reorder loaded tasks |
| `BoardAccessCheckEvent` | `TaskBoardEvents::BOARD_ACCESS_CHECK` | Grant/deny board access |
| `TaskAccessCheckEvent` | `TaskBoardEvents::TASK_ACCESS_CHECK` | Grant/deny task access |
| `TaskReadOnlyResolveEvent` | `TaskBoardEvents::TASK_READ_ONLY_RESOLVE` | Mark task as read-only |
| `MemberListQueryEvent` | `TaskBoardEvents::MEMBER_LIST_QUERY` | Override member list query |

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
| `firewall` | `main` | Documented firewall name for host apps |

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
