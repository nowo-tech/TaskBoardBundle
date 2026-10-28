# Usage

## Table of contents

- [Manage UI](#manage-ui)
- [Task operations](#task-operations)
- [TimeTrack integration (optional)](#timetrack-integration-optional)
- [Teams](#teams)
- [Import tasks from ClickUp, Jira, or Trello](#import-tasks-from-clickup-jira-or-trello)
- [Custom access control](#custom-access-control)
  - [Route-level checker](#route-level-checker)
  - [Event listeners](#event-listeners)
- [Twig overrides](#twig-overrides)
  - [Forms (REQ-TWIG-005)](#forms-req-twig-005)
- [Layout without forking pages](#layout-without-forking-pages)
- [Multi-tenant hosts (since 1.10)](#multi-tenant-hosts-since-110)
  - [Boards per tenant (`tenancy`)](#boards-per-tenant-tenancy)
  - [Links to host objects](#links-to-host-objects)
  - [Who may be assigned](#who-may-be-assigned)
  - [Events after writes](#events-after-writes)
  - [Template blocks and partials](#template-blocks-and-partials)
- [Translations](#translations)

## Manage UI

Authenticated users open the manage UI (default `/tools/task-board`):

- **Index** — list active boards and create new boards (default columns: To do, In progress, Done).
- **Kanban board** — drag-and-drop tasks between columns; create tasks and columns.
- **List view** — filterable task table with status, priority, assignee, and due date. Completed tasks (last column) are included; filter by column to hide them.
- **Gantt view** — timeline with task bars and blocking dependencies. Completed tasks are drawn as finished bars; subtasks whose parent is not on the board's timeline appear at root level.
- **Task detail** — description, subtasks, members, links, change history, and aggregated time.

## Task operations

| Action | Route key | Description |
|--------|-----------|-------------|
| Import tasks | `board_import` | Upload CSV/JSON from ClickUp, Jira, Trello, etc. |
| Move column | `task_move` | Drag task to another column (POST); positions in the target column are renumbered 0..n-1 |
| Advance / regress | `task_advance` | Move to next/previous column |
| Add link | `task_link` | Attach external URL (GitLab MR URLs are parsed) |
| Edit link | `task_link_update` | Change type, URL, label or external id of a link |
| Remove link | `task_link_remove` | Delete a link (POST + CSRF) |
| Add member | `task_member` | Assign team member with role |
| Remove member | `task_member_remove` | Unassign a member (POST + CSRF) |
| Add subtask | `task_subtask` | Create child task |
| Set priority | `task_priority` | Update task priority |

## TimeTrack integration (optional)

When TimeTrack is installed and configured with `nowo_task_board.task_provider`:

- Users can start/stop timers on trackable tasks (assignee or team member).
- On timer stop, `TimeSpentAggregatorListener` adds duration to `task.total_time_seconds`.
- Team context for TimeTrack comes from `nowo_task_board.team_context_provider`.

## Teams

Boards can be linked to a `Team`. Team members (manager/member roles) can track time on team tasks via `TaskAccessGuard`.

Configure a custom `team_membership_resolver` for advanced team ACL — see [CONFIGURATION.md](CONFIGURATION.md).

## Import tasks from ClickUp, Jira, or Trello

1. Open a board → **Import tasks** (or `/tools/task-board/board/{boardId}/import`).
2. Choose the source format and upload the export file.
3. Enable **Create missing status columns** to map ClickUp/Jira statuses to new kanban columns.
4. Enable **Skip already imported tasks** to avoid duplicates (matched by external task ID).

CLI:

```bash
php bin/console nowo:task-board:import <board-uuid> /path/to/export.csv --source=clickup_csv
```

Supported `--source` values: `clickup_csv`, `clickup_json`, `jira_csv`, `trello_json`.

**Export guides (English):** [docs/import/](import/README.md) — step-by-step instructions for [ClickUp](import/clickup.md), [Jira](import/jira.md), and [Trello](import/trello.md).

## Custom access control

Two layers:

| Layer | Interface / event | Question |
|-------|-------------------|----------|
| Route features | `TaskBoardAccessCheckerInterface` | Can the user open manage UI, create boards, or list boards? |
| Per board/task | `TaskBoardResourceAccessChecker` (`security.manage_roles`, `security.resource_scoping`) + `BoardAccessCheckEvent`, `TaskAccessCheckEvent` | Can the user view or edit this board/task? Default: board creator, team members, task members and `manage_roles`. |

### Route-level checker

```yaml
nowo_task_board:
    security:
        access_checker: App\Security\TeamTaskBoardAccessChecker
```

### Event listeners

Register Symfony event listeners for fine-grained ACL — see [CONFIGURATION.md](CONFIGURATION.md#access-events).

## Twig overrides

Place templates under:

```
templates/bundles/NowoTaskBoardBundle/
├── manage/index.html.twig
├── manage/board.html.twig
├── manage/list.html.twig
├── manage/gantt.html.twig
├── manage/task.html.twig
├── manage/import.html.twig
└── layout.html.twig
```

Or configure paths in `nowo_task_board.templates.*`.

**Prefer config over full-file overrides** for chrome: set `templates.layout` to your project layout so you keep receiving UI fixes on package upgrades. A full copy of a manage page hides vendor updates for that file until you merge them.

### Forms (REQ-TWIG-005)

Every control of the manage UI is a Symfony Form (types under `Nowo\TaskBoardBundle\Form`, FormKit options on the editors) rendered with `form_start`, the child loop and `form_end`. Besides the editor forms, the controller always passes:

| Template | Variable | Type |
|----------|----------|------|
| `manage/list.html.twig` | `filterForm` | `TaskListFilterType` — unmapped, client-side filters (Stimulus targets kept) |
| `manage/task.html.twig` | `advanceForm` | `CsrfActionType` (`task_board_task_advance`; the status buttons post into `#nowo-task-advance-form`) |
| `manage/_task_links_modal.html.twig` | `linkRemoveForm` | `CsrfActionType` (`task_board_task_link_remove`; the modal controller sets the action) |
| `manage/_task_members_modal.html.twig` | `memberRemoveForms` (keyed by member id) | `CsrfActionType` (`task_board_task_member_remove_<id>`) |
| `manage/_task_priority_modal.html.twig` | `priorityForm` | `TaskPriorityFormType` (flat `priority` radios + `_token`) |

Overrides and demos must not reintroduce raw `<form>` / `<input>` / `<select>` / `<textarea>` markup (`make check-no-raw-html-form`, part of `release-check`). Pattern:

```twig
{{ form_start(advanceForm, {attr: {id: 'nowo-task-advance-form', class: 'd-none'}}) }}
    {% for child in advanceForm %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
{{ form_end(advanceForm) }}
```

## Layout without forking pages

1. Set `nowo_task_board.templates.layout` to your project layout (e.g. `base.html.twig`) or a one-file bridge — see [CONFIGURATION.md — Layout integration](CONFIGURATION.md#layout-integration-req-ui-001).
2. Set `nowo_task_board.templates.css_framework` to match your stack (`tabler` default, or `bootstrap5` / `custom` / …). Twig global: `nowo_task_board_css_framework`.
3. Manage pages `{% extends nowo_task_board_layout %}` and call `{{ parent() }}` in `stylesheets` / `javascripts` so host and bundle assets (`task-board.css` / `task-board.js`) stack.
4. Default `@NowoTaskBoardBundle/layout.html.twig` is a **demo** full HTML document (Tabler CDN when `css_framework` is Tabler/Bootstrap). Host apps should not rely on forking it for production chrome.
5. Manage markup ships Tabler / Bootstrap 5-compatible classes. With `custom`, style semantic `nowo-ui-*` classes from the host — you do not need to fork every page.

```yaml
nowo_task_board:
    templates:
        layout: 'base.html.twig'
        css_framework: bootstrap5   # or tabler | custom
```

## Multi-tenant hosts (since 1.10)

Everything in this section is opt-in: without the options below the bundle behaves as in 1.9.

### Boards per tenant (`tenancy`)

A host whose boards belong to a tenant (a project, a customer, a workspace) maps one or several boards to each tenant
without decorating the router or overriding controllers:

```yaml
# config/packages/nowo_task_board.yaml
nowo_task_board:
    tenancy:
        enabled: true
        single_board: true            # the tenant index opens its board, created on the first visit
        resolver: App\Task\ProjectTaskBoardTenantResolver
        tenant_paths:
            - path: /projects/{tenantSlug}/tasks
              name_prefix: app_project_
              slug_requirement: '\d+'
    board:
        default_columns:              # columns of a new board, the last one is "done"
            - { name: To do }
            - { name: In progress }
            - { name: Review }
            - { name: Done, color: '#22c55e' }
        top_level_only: true          # the Kanban board shows top-level tasks; subtasks are counted on their card
```

- Every manage route is copied under each `tenant_paths` entry as `<name_prefix><route name>`, e.g.
  `app_project_nowo_task_board_board` → `/projects/{tenantSlug}/tasks/board/{boardId}`. The first entry whose
  `slug_prefix` (default `''`: every slug) the slug starts with serves it.
- The router is decorated (only when `tenancy.enabled`): `path('nowo_task_board_board', {boardId: id})` inside a
  tenant page links to the tenant copy and fills `{tenantSlug}` from the current request; from a host page pass it
  (`path('nowo_task_board_task', {taskId: id, tenantSlug: project.id})`). `Nowo\TaskBoardBundle\Routing\TenantPaths`
  maps a copied route name back to the bundle route (`bundleRoute()`), for host listeners.
- `TaskBoardTenantResolverInterface::resolve(string $tenantSlug, object $user): ?TaskBoardTenant` maps the slug to the
  tenant (`key` stored on its boards, `slug`, `label` used as the board name with `single_board`), or returns null
  for a slug the user may not open (404). Without a resolver the slug is the tenant.
- A board or task of another tenant answers **404**; the tenant-less routes answer 404 too while tenancy is on. New
  boards get the tenant key (`TaskBoard::getTenantKey()`); `TaskBoardRepositoryInterface::findOneByTenant()` /
  `findActiveByTenant()` look them up. Per-board resource scoping (`security.resource_scoping`) still applies: a host
  that authorizes by tenant usually sets it to `false` and checks its own permission in a `kernel.request` /
  `kernel.controller` listener on the copied routes.

### Links to host objects

A task can link to an object of the host (an issue, an order, a ticket) with `TaskLinkType::HostObject`:

```php
$linkAttacher->attachObject($task, 'issue', (string) $issue->getId(), $user, 'BEACON-12');   // idempotent
$linkAttacher->detachObject($task, 'issue', (string) $issue->getId(), $user);
$tasks = $taskRepository->findByHostObject('issue', (string) $issue->getId());              // the tasks of an object
```

Implement `Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface` (autoconfigured, tag
`nowo_task_board.object_link_resolver`) to describe the objects: `supports($type)`, `label($type, $id)`,
`url($type, $id)`, `badge($type, $id)` (a `TaskObjectBadge` with a label and a colour variant). The task page shows the
label, link and badge; Twig has `nowo_task_board_link_label(link)`, `nowo_task_board_link_url(link)` and
`nowo_task_board_link_badge(link)`. Host object links are not offered in the link form (`TaskLinkType::userChoices()`).

### Who may be assigned

`assignee_provider: App\Task\ProjectMemberAssigneeProvider` (a `Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface`)
limits the member picker to `assignableUsers(TaskBoard $board)` and makes `TaskMemberAssigner::assign()` throw
`UnassignableUserException` (flash `task_board.flash.member_not_assignable`) for a user `canBeAssigned()` refuses.

### Events after writes

Dispatched after the change is flushed (listen with `#[AsEventListener(event: TaskBoardEvents::TASK_CREATED)]`):

| Constant | Event | Payload (besides `getTask()`, `getActor()`) |
|---|---|---|
| `TASK_CREATED` | `TaskCreatedEvent` | — (also for subtasks: `getTask()->getParent()`) |
| `TASK_UPDATED` | `TaskUpdatedEvent` | `getChangedFields()` (title, description, priority, column, estimatedMinutes, dueAt, tags), `hasChanged()` |
| `TASK_MOVED` | `TaskMovedEvent` | `getFromColumn()`, `getToColumn()` (a reorder in the same column is not a move) |
| `TASK_COMPLETED` | `TaskCompletedEvent` | — (moved to the last column) |
| `TASK_REOPENED` | `TaskReopenedEvent` | — (left the last column) |
| `TASK_MEMBER_ADDED` | `TaskMemberAddedEvent` | `getMember()`, `getUser()` |

### Template blocks and partials

Restyle a page by extending it and overriding a block instead of copying it:

| Page | Blocks |
|---|---|
| `manage/index.html.twig` | `nowo_task_board_toolbar` |
| `manage/board.html.twig` | `nowo_task_board_toolbar`, `nowo_task_board_flashes`, `nowo_task_board_columns`, `nowo_task_board_column` (per column) |
| `manage/list.html.twig` | `nowo_task_board_toolbar`, `nowo_task_board_list` |
| `manage/task.html.twig` | `nowo_task_panel`, `nowo_task_toolbar`, `nowo_task_subtasks`, `nowo_task_relations` |

Partials: `_board_column.html.twig` (`column`, `tasks`), `_task_card.html.twig` (`task`, optional `taskTree` for the
subtask counter) and `_subtask_tree.html.twig` (`tasks`, `taskTree`, `depth`): a recursive tree of any depth with a
done / total counter per node and `<details>` to expand / collapse. The controller passes `taskTree`
(`Nowo\TaskBoardBundle\Support\TaskTree`, built from the board's tasks in one query) to the board, list and task pages;
`nowo_task_board_subtask_progress(task)` gives done / total from `task.subtasks` when no tree is at hand. Semantic
classes: `nowo-task-column`, `nowo-task-card` (`__title`, `__meta`, `__priority`, `__subtasks`), `nowo-task-subtree`
(`__item`, `__row`, `__progress`, `__children`), `nowo-task-panel`, `nowo-task-link--host-object`.

## Translations

Domain: `NowoTaskBoardBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoTaskBoardBundle/` or `translations/NowoTaskBoardBundle.en.yaml`.
