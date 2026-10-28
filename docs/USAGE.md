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
  - [Read-only access (since 1.10)](#read-only-access-since-110)
  - [Links to host objects](#links-to-host-objects)
  - [Who may be assigned](#who-may-be-assigned)
  - [Events after writes](#events-after-writes)
  - [Template blocks and partials](#template-blocks-and-partials)
- [Columns, column sets and the task panel (since 1.11)](#columns-column-sets-and-the-task-panel-since-111)
  - [Column icon, rename, colour, delete](#column-icon-rename-colour-delete)
  - [Column sets](#column-sets)
  - [Several boards per tenant and boards owned by a user](#several-boards-per-tenant-and-boards-owned-by-a-user)
  - [Task panel in a side drawer](#task-panel-in-a-side-drawer)
  - [No inline styles](#no-inline-styles)
- [Look and feel: ClickUp-like views, theming and icons (since 1.12)](#look-and-feel-clickup-like-views-theming-and-icons-since-112)
  - [Theming with design tokens](#theming-with-design-tokens)
  - [Icons](#icons)
  - [Inline new task](#inline-new-task)
- [Task colour and dialogs (since 1.14)](#task-colour-and-dialogs-since-114)
- [Dependencies, time and Gantt scales (since 1.15)](#dependencies-time-and-gantt-scales-since-115)
  - [Dependencies](#dependencies)
  - [Time tracking and deviation](#time-tracking-and-deviation)
  - [List: folding filters and time columns](#list-folding-filters-and-time-columns)
  - [Gantt scales and week start](#gantt-scales-and-week-start)
  - [Start date, parent path, sorting and tall columns](#start-date-parent-path-sorting-and-tall-columns)
  - [Collapsible sections](#collapsible-sections)
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
| Add dependency | `task_dependency` | Since 1.15: "this task blocks / is blocked by / relates to / duplicates" another task of the board (POST + CSRF) |
| Remove dependency | `task_dependency_remove` | Since 1.15 (POST + CSRF) |
| Log time | `task_time` | Since 1.15: minutes, day and note (POST + CSRF) |
| Remove time entry | `task_time_remove` | Since 1.15 (POST + CSRF) |

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

### Read-only access (since 1.10)

Board writes (columns, new tasks, import) need `TaskBoardResourceAccessChecker::canEditBoard()`; task writes need
`canEditTask()`. A `BOARD_ACCESS_CHECK` listener gives read-only access with `grant()` + `markReadOnly()` (the tasks of
the board become read-only too, unless a `TASK_ACCESS_CHECK` listener grants a task explicitly):

```php
#[AsEventListener(event: TaskBoardEvents::BOARD_ACCESS_CHECK)]
public function onBoardAccess(BoardAccessCheckEvent $event): void
{
    $project = $this->projects->forTenantKey($event->getBoard()->getTenantKey());
    if (!$this->security->isGranted('project.tasks.view', $project)) {
        $event->deny();

        return;
    }

    $event->grant();
    if (!$this->security->isGranted('project.tasks.manage', $project)) {
        $event->markReadOnly();
    }
}
```

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
| `manage/task.html.twig` | `nowo_task_panel`, `nowo_task_toolbar`, `nowo_task_subtasks`, `nowo_task_relations` (since 1.11 defined in `_task_blocks.html.twig`, imported with `{% use %}` by the task page and the task panel; override them in a template extending `task.html.twig` as before) |

Partials: `_board_column.html.twig` (`column`, `tasks`), `_task_card.html.twig` (`task`, optional `taskTree` for the
subtask counter) and `_subtask_tree.html.twig` (`tasks`, `taskTree`, `depth`): a recursive tree of any depth with a
done / total counter per node and `<details>` to expand / collapse. The controller passes `taskTree`
(`Nowo\TaskBoardBundle\Support\TaskTree`, built from the board's tasks in one query) to the board, list and task pages;
`nowo_task_board_subtask_progress(task)` gives done / total from `task.subtasks` when no tree is at hand. Semantic
classes: `nowo-task-column`, `nowo-task-card` (`__title`, `__meta`, `__priority`, `__subtasks`), `nowo-task-subtree`
(`__item`, `__row`, `__progress`, `__children`), `nowo-task-panel`, `nowo-task-link--host-object`.

## Columns, column sets and the task panel (since 1.11)

All opt-in or backward compatible: a host that does nothing keeps the 1.10 pages, plus the column icon field and the
column set buttons for board editors.

### Column icon, rename, colour, delete

Board editors (`canEditBoard`) rename, recolour and give an icon to a column in the edit dialog, and delete it there
(`column_delete`, CSRF token id `task_board_column_delete`): its tasks move to the end of the first remaining column
(completed when that column is the last one, reopened otherwise) and the last column of a board is never deleted
(flash `task_board.flash.column_last`). Read-only users see none of these controls and get 403 on the routes.

`BoardColumn::$icon` holds a name such as `tabler:bug`, picked from `board.column_icons`. The column header renders it
through `manage/_column_icon.html.twig` → UiKit `components/_icon.html.twig`: with `nowo_ui_kit.icon_set: ux_icon` the
full name goes to `ux_icon()` (import the icons you offer in the host, e.g. `bin/console ux:icons:import tabler:bug`),
with the webfont sets the bare name (`ti ti-bug`); `icon_set: none` shows no icon.

### Column sets

A column set (`Nowo\TaskBoardBundle\Entity\ColumnSet`, table `task_board_column_sets`) is a named, ordered list of
`{name, color, icon}`. On the board page editors get **Save columns as set** (`column_set_create`) and **Add columns
from set** (`column_set_apply`: copies appended after the board's columns; the dialog also lists the sets with a delete
button for those `canDeleteColumnSet()` allows, `column_set_delete`). The new board form offers the sets
(`TaskBoardFormData::$columnSetId`); `TaskBoardCreator::create($data, $creator, $tenantKey, $columnSet)` uses the set's
columns instead of `board.default_columns`. Boards always get copies: editing a board never changes a set.

`Nowo\TaskBoardBundle\Service\ColumnSetManager` does the work (`available()`, `find()`, `saveFromBoard()`,
`applyToBoard()`, `delete()`). Which sets a user sees — and the scope stored on a new set — come from
`TaskBoardEvents::COLUMN_SET_QUERY`: the default scope is the board's tenant key (a tenant's sets stay in the tenant),
null without tenancy. A host that shares the sets across tenants sets it to null:

```php
#[AsEventListener(event: TaskBoardEvents::COLUMN_SET_QUERY)]
public function shareColumnSets(ColumnSetQueryEvent $event): void
{
    $event->setScopeKey(null);           // or overrideList([...]) to pick the sets yourself
}
```

Deleting a set: its creator or a `security.manage_roles` role, or what a `COLUMN_SET_ACCESS_CHECK` listener decides
(`ColumnSetAccessCheckEvent::grant()` / `deny()`).

### Several boards per tenant and boards owned by a user

With `tenancy.single_board: false` the tenant index lists the tenant's boards (`findActiveByTenant()`, filtered by
`BOARD_ACCESS_CHECK`; `BoardListQueryEvent::getTenantKey()` names the tenant, since 1.11) next to the **New board**
form, which stores the tenant key and offers the column sets. A board "owned by a user" is a tenant like any other: a
resolver that maps `user-<id>` slugs to a `TaskBoardTenant` (and returns it for the users the boards are shared with),
plus a `BOARD_ACCESS_CHECK` listener that grants the owner and the people a board is shared with (`markReadOnly()` for
view-only shares) and denies everyone else, is all the bundle needs.

### Task panel in a side drawer

`GET task_panel` (`…/task/{taskId}/panel`) renders `templates.task_panel` (`manage/task_panel.html.twig`): the task
page's blocks (edit form, members, priority, column, subtasks, links) without the layout. Board cards, list rows and
Gantt bars carry `data-task-panel-url` (the panel URL with `_target_path` = the current page); the title links keep
opening the task page without JavaScript. Host JavaScript opens its drawer with that URL:

```js
document.addEventListener('click', async (event) => {
    const link = event.target.closest('[data-task-panel-url] a[href]');
    if (!link) return;
    event.preventDefault();
    const url = link.closest('[data-task-panel-url]').dataset.taskPanelUrl;
    drawer.innerHTML = await (await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})).text();
    drawer.showModal();
});
```

Every form of the panel posts to the usual routes with `?_target_path=<page>`: without fetch they redirect back there
(only a local path is followed: no scheme, no `//`, no backslash); sent with fetch (`X-Requested-With: XMLHttpRequest`
or a `Turbo-Frame` header) they answer the refreshed panel (an invalid edit answers 422 with the errors), so the drawer
replaces its content and the host reloads the board when it closes. The task page itself answers the panel to a fetch
too. The page around the drawer must load `task-board.css` / `task-board.js` (the board, list and Gantt pages do).

### No inline styles

The templates carry no `style=""` (CSP without `'unsafe-inline'`): colours, Gantt positions and depths are CSS custom
properties written as JSON in `data-nowo-task-vars` and set by `task-board.js` through the CSSOM (also inside panels
inserted later). The subtask progress is a native `<progress>`. New semantic classes: `nowo-task-column--colored`,
`nowo-task-column__icon`, `__header`, `__title`, `__name`, `__count`, `__edit`, `__tasks`, `__empty`, `__add-task`,
`nowo-task-column-sets` (`__item`, `__delete`, `__empty`), `nowo-task-dialog` (`--native`, `__header`, `__body`,
`__actions`, `__danger`), `nowo-task-progress`, `nowo-task-list__row`, `nowo-task-panel-fragment`.

## Look and feel: ClickUp-like views, theming and icons (since 1.12)

The views follow ClickUp's way of working; their look is the host's:

- **Task detail** (page and panel): a status pill in the column's colour (its menu moves the task; the arrows step to the
  previous / next column, the check completes it), the priority flag, the title edited in place, then one grid of
  attributes (people, due date, time, tags) and the description; below, the subtasks as a checklist with an inline
  *Add subtask* (Enter adds), links, dependencies, documentation and the activity stream.
- **Board**: cards with tags, priority flag (only when not *normal*), due date (late dates in the danger colour),
  estimate, subtask progress and assignee avatars; an inline *+ New task* at the foot of every column.
- **List**: one collapsible group per column (its colour, icon and count) with the tasks as rows — title, people, due
  date, priority, tags — and an inline *+ New task* per group; tasks without a column close the list.

The templates lay out with the bundle's own classes (`nowo-task-detail__*`, `nowo-task-section*`, `nowo-task-rows`,
`nowo-task-activity`, `nowo-task-list__*`, `nowo-task-card__*`, `nowo-task-inline-add`, `nowo-task-avatar(s)`,
`nowo-task-priority--*`, `nowo-task-tags`, `nowo-task-sr-only`) styled by `task-board.css`, so they need no CSS framework
utilities; `btn` / `badge` classes stay for hosts whose framework styles them.

### Theming with design tokens

Every colour, border and radius of `task-board.css` reads a `--nowo-task-*` token, then the Tabler variable, then a
neutral value. Set the tokens on any ancestor to give the board, list, Gantt, task page and panel your look:

```css
:root {
    --nowo-task-surface: #ffffff;       /* cards, sections */
    --nowo-task-surface-muted: #f8fafc; /* columns */
    --nowo-task-border: #e6e7e9;
    --nowo-task-ink: #1d273b;
    --nowo-task-muted: #626976;
    --nowo-task-hover: #f1f5f9;
    --nowo-task-accent: #206bc4;        /* links, focus, avatars */
    --nowo-task-accent-soft: #eff6ff;
    --nowo-task-radius: 8px;
    --nowo-task-radius-sm: 4px;
    --nowo-task-success: #22c55e;       /* also -danger, -warning, -info, -purple, each with a -soft variant */
    --nowo-task-overlay: rgb(15 23 42 / 0.55); /* dialog backdrop (since 1.13.1), with --nowo-task-overlay-blur */
}
```

A dark theme redefines them under its own selector (e.g. `[data-theme="dark"]`).

### Icons

The icons go through UiKit's `components/_icon.html.twig` with `nowo_ui_kit_icon_set` — no icon webfont is needed:
with `ux_icon` the bundle asks for `tabler:<name>`; with the Tabler webfont `ti ti-<name>`. A host on `ux_icon` imports
the icons once (`php bin/console ux:icons:import tabler:<name> …`): arrow-left, arrow-right, arrows-sort, calendar,
calendar-event, check, chevron-down, chevron-left, chevron-right, circle, circle-check, circle-dot, clock, clock-play,
columns, device-floppy, external-link, flag, git-branch, grip-vertical, history, layout-columns, layout-kanban,
line-dashed, link, list, list-tree, notes, palette, pencil, plus, subtask, tags, trash, upload, user-minus, user-plus,
users, x.

### Inline new task

The board and list actions pass `inlineTaskForms` (column id → a compact task form, editors only). It posts to
`task_create` with `?inline=1&_target_path=<page>`: the compact form (title and the hidden column, normal priority)
creates the task in that column and the page comes back (`_target_path` must be a local path). The *Add task* dialog
stays for hosts whose controller does not pass the forms.

## Task colour and dialogs (since 1.14)

**Task colour.** A task may have its own colour (`Task::getColor()`, `#rrggbb` or null). It is picked in the task
detail ("Colour" row) and in the *Add task* dialog with the same palette as the column dialogs, a custom colour and
*No colour*. Where it shows:

- **Gantt**: the task's bar takes the colour (border and tinted background through `--nowo-task-bar-color`); a task
  without one keeps the priority colours.
- **Board**: the card shows the colour on its left edge (`nowo-task-card--colored`, `--nowo-task-card-color`).
- **Activity**: a change is recorded (`TaskChangeType::Color`) and shown as swatches; `TaskUpdatedEvent` lists `color`.

A native colour input cannot be empty, so `TaskFormType` keeps the value in a hidden `color` field (`#rrggbb` only,
empty = no colour) and adds an unmapped `customColor` (`ColorType`); the `nowo-task-board-picker` controller writes
the swatch or the custom colour into the hidden field. Custom templates include the shared partial:

```twig
{{ include('@NowoTaskBoardBundle/manage/_colour_picker.html.twig', {
    field: taskForm.color,
    custom: taskForm.customColor,
    groupLabel: 'task_board.form.task.color'|trans({}, 'NowoTaskBoardBundle'),
}) }}
```

**Dialogs.** Every dialog of the bundle is a native `<dialog class="nowo-task-dialog nowo-task-dialog--native">`
(*Add column*, *Edit column*, column sets since 1.13; *Add task*, *Manage people*, *Change priority*, *Add / edit link*
since 1.14): opened with `showModal()` by its Stimulus controller (top layer, Escape and the focus trap from the
browser, a click on the backdrop closes it), coloured by the `--nowo-task-*` tokens with the `--nowo-task-overlay`
backdrop, laid out with the bundle's classes (`nowo-task-dialog__header`, `__title`, `__close`, `__body`, `__grid`,
`__section`, `__actions`, `nowo-task-field`, `nowo-task-members__*`). Overrides that kept the Bootstrap `.modal`
markup still open through the controllers' legacy branch.

## Dependencies, time and Gantt scales (since 1.15)

No schema change: the dependencies (`task_board_task_dependencies`) and the manual time entries
(`task_board_task_time_entries`) already had their tables.

### Dependencies

The task detail and panel list the task's dependencies in both directions ("Blocks", "Blocked by", "Related to",
"Duplicates", "Duplicated by"). Editors get **Add dependency** — a native dialog with the relation and a list of the
board's other tasks filtered as you type (`nowo-task-board-option-filter` controller) — and a remove button per row.
`TaskDependencyManager` refuses, with a flash message:

| Refusal | Flash key |
|---------|-----------|
| The task itself | `task_board.flash.dependency_self` |
| A task of another board | `task_board.flash.dependency_other_board` |
| The same link again (also "A blocks B" after "B blocked by A", "related" either way) | `task_board.flash.dependency_duplicate` |
| A cycle of "blocks" links (A blocks B blocks … A) | `task_board.flash.dependency_cycle` |

Writes need edit access (`TASK_ACCESS_CHECK`; read-only boards answer 403) and are recorded in the activity
(`TaskChangeType::DependencyAdded` / `DependencyRemoved`, the relation in the entry's context). The Gantt draws the
"blocks" links as before; a Gantt bar opens the task panel, where the dependency can be added.

### Time tracking and deviation

The detail's **Time tracking** section shows the estimate, the time spent (TimeTrack timers plus manual entries) and
the deviation — the difference in time and %, coloured *on track* / *over* / *under* (`Support\TaskDeviation`: *over*
when more was spent than estimated, *under* when a completed task used less than 90 % of it) — the subtasks' sum
beside the task's own, the manual entries and **Log time** (minutes, day, note; `TaskTimeLogger`, recorded as
`TimeLogged` / `TimeRemoved`). Durations read "45 min", "2 h", "1 h 30 min" (Twig filter `nowo_task_board_duration`,
function `nowo_task_board_deviation(task)`).

### List: folding filters and time columns

The list filters fold behind a **Filters** toggle (the disclosure chevron, `aria-expanded`, the state per board in
localStorage, open while a filter is active; `nowo-task-board-filter-collapse` controller). Two more fields:
**Over estimate** (only the tasks that took longer than estimated) and **Show time** (the Estimate, Spent and Deviation
columns, remembered per board), with the totals of the shown rows at the foot of every group and below the list.

### Gantt scales and week start

`?scale=hours|days|weeks|months` (default `days`) picks the column unit; the toolbar offers the four scales and the
last one used is remembered per board (localStorage). Each scale draws at least and at most a number of columns
(`GanttScale::minSlots()` / `maxSlots()`: 48 to 168 hours, 21 to 366 days, 12 to 104 weeks, 12 to 60 months); a
longer plan is clamped to a window around now and the chart says so (`TaskGanttTimeline::$clamped`; bars running past
it are clipped, bars outside it are not drawn). In the Hours scale a task whose due date carries a time runs from the
hour it was created to its due time; date-only tasks span whole days. Weekends are shaded in the Hours and Days scales.

Bars show the time spent against the estimate as a fill (capped at the bar's end) with an over-run marker past 100 %,
and the numbers in the bar's tooltip. New template data: `timeline.slots` (`TaskGanttSlot`), `timeline.scale`,
`timeline.weekStart`, `timeline.clamped`, `timeline.todayIndex`, `scales`; `TaskGanttItem` gains the estimate, spent,
`spentPercent`, `deviationStatus`, `overrun`, `visible`, `clippedStart` / `clippedEnd`, `startTime` / `endTime`.

The **week start** (`monday` or `sunday`) sets the Weeks scale columns and the week separators of the Days scale.
`gantt.week_start` sets it for everyone; a host answers per request (e.g. a user preference) by implementing
`Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface` and naming it in `gantt.week_start_resolver`:

```php
final readonly class UserWeekStartResolver implements WeekStartResolverInterface
{
    public function __construct(private Security $security) {}

    public function resolve(): WeekStart
    {
        $user = $this->security->getUser();

        return $user instanceof User && $user->weekStartsOnSunday() ? WeekStart::Sunday : WeekStart::Monday;
    }
}
```

### Start date, parent path, sorting and tall columns

- **Start date** (`Task::$startAt`, nullable): next to the due date in the detail and the *Add task* dialog; the Gantt
  bar starts there in every scale (`Task::getPlannedStart()` = the start date, else the creation). A start after the
  due date is a form error.
- **Parent path**: a subtask's detail shows its parents ("Epic › Story › this task"), from
  `nowo_task_board_ancestors(task)`.
- **List sorting**: the *Sort* select of the filters orders the rows inside every group by title, priority (urgent
  first), due date (none last) or column; empty keeps the board order.
- **Tall columns**: `--nowo-task-column-max-height: 70vh; --nowo-task-column-overflow: auto;` on the board (or an
  ancestor) makes a long column scroll inside itself.
- **Lengths**: titles and names are limited to their column (`TaskFormType::TITLE_MAX` = 255) with `maxlength`.

### Collapsible sections

Every collapsible of the task detail and panel (Documentation, Activity, the subtask tree's children) and the list
filters use the platform disclosure toggle: UiKit's `_disclosure_icon` partial with its morph icon, through
`@NowoTaskBoardBundle/manage/_disclosure.html.twig`, and `data-controller="disclosure-icon"` on the `<summary>` or
button (register UiKit's `disclosure-icon` peer for the animation; the static icons follow the state without it).

**Theming.** New optional tokens: `--nowo-task-gantt-spent` (the spent fill), `--nowo-task-overrun` (over-estimate
marks, default the danger colour), `--nowo-task-on-track` and `--nowo-task-under` (deviation colours, default success
and warning). **Icons** (`ux_icon` hosts): `tabler:hourglass`, `tabler:filter` and `tabler:calendar-event`.

## Translations

Domain: `NowoTaskBoardBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoTaskBoardBundle/` or `translations/NowoTaskBoardBundle.en.yaml`.
