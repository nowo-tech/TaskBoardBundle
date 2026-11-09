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
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Board index and default view (since 1.24)](#board-index-and-default-view-since-124)
- [Columns, column sets and the task panel (since 1.11)](#columns-column-sets-and-the-task-panel-since-111)
  - [Column icon, rename, colour, delete](#column-icon-rename-colour-delete)
  - [Column sets](#column-sets)
  - [Several boards per tenant and boards owned by a user](#several-boards-per-tenant-and-boards-owned-by-a-user)
  - [Task panel in a side drawer](#task-panel-in-a-side-drawer)
  - [Previous / next task (since 1.20)](#previous--next-task-since-120)
  - [No inline styles](#no-inline-styles)
- [Look and feel: ClickUp-like views, theming and icons (since 1.12)](#look-and-feel-clickup-like-views-theming-and-icons-since-112)
  - [Theming with design tokens](#theming-with-design-tokens)
- [Task API (since 2.2)](#task-api-since-22)
- [Host hooks (since 2.0.0)](#host-hooks-since-200)
  - [Icons](#icons)
  - [Inline new task](#inline-new-task)
- [Task colour and dialogs (since 1.14)](#task-colour-and-dialogs-since-114)
- [Comments and @mentions (since 1.16)](#comments-and-mentions-since-116)
- [Dependencies, time and Gantt scales (since 1.15)](#dependencies-time-and-gantt-scales-since-115)
  - [Dependencies](#dependencies)
  - [Time tracking and deviation](#time-tracking-and-deviation)
  - [List: folding filters and time columns](#list-folding-filters-and-time-columns)
  - [Gantt scales and week start](#gantt-scales-and-week-start)
  - [Start date, parent path, sorting and tall columns](#start-date-parent-path-sorting-and-tall-columns)
  - [Collapsible sections](#collapsible-sections)
- [Provider connections and archived tasks (since 1.23)](#provider-connections-and-archived-tasks-since-123)
- [Task member pictures (since 1.23)](#task-member-pictures-since-123)
- [Task numbers and calendar (since 2.1)](#task-numbers-and-calendar-since-21)
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
| Move to another board | `task_move_board` | Since 2.3, with `move.enabled`: GET choices and preview, POST + CSRF to confirm |
| Duplicate | `task_duplicate` | Since 2.3, with `move.enabled`: GET choices and preview, POST + CSRF to confirm |
| Repeat | `task_recurrence`, `task_recurrence_action` | Since 2.3, with `recurrence.enabled`: make recurring, edit; pause, resume, stop, skip, undo a skip (POST + CSRF per action) |

### Move and duplicate (since 2.3)

`move.enabled: true` adds *Move to another board* and *Duplicate* to the task panel, for who may edit the task and its board. Each page chooses with a GET form and shows the preview under it, what is lost first; the confirmation posts the preview's fingerprint, so a preview out of date moves nothing and is shown again. The target boards come from `move.targets` (`TaskBoardTargetsInterface`); any other answers 404.

From code, with no request: `TaskCopier::copy($task, new TaskCopyOptions(...), $actor)` (and `preview()`), `TaskMovePlanner::plan($task, $target, $columnMap)` then `TaskMover::move($task, $target, $columnMap, $plan->fingerprint, $actor)`. Check access first. Refusals are `TaskCopyRefusedException` with a `reason`, nothing written.

| What | Move | Duplicate |
| --- | --- | --- |
| Subtasks | The whole tree, always | Open subtasks, by option (archived ones left out) |
| Comments, documents, links | Stay on the task | Documents copied; comments and links never |
| Tracked time | Stays on the task, never deleted; snapshots follow | Never copied (the copy starts at 0) |
| Members | Kept when assignable on the target, else removed (without an assignee provider, everyone is assignable) | Copied when assignable, by option |
| Access | `canEditTask()` on every task that moves, else refused | Subtasks the user cannot view are left out |
| Imported fields | Kept on the task, hidden on the target board (the panel, the list search and the Task API show only the fields of the task's own board); nothing is deleted, and the source board's erasure still reaches them | Never copied |
| Dependencies | Kept inside the tree; with tasks that stay, removed | Never copied |
| Column | Same name, or chosen; completed tasks to the last column | The same rule, always open |
| Provider link | Detached for good | Never copied |
| Number | Kept in the scope; a new one with the old as alias across scopes | A new one |
| Events | `TASK_BOARD_CHANGED` after the commit, no `TASK_MOVED` | `TASK_CREATED` per task, `TASK_MEMBER_ADDED` |

### Why a change happens (since 2.3)

```php
$context->run('rule:' . $rule->getId(), fn () => $taskManager->moveToColumn($task, $columnId, $author));
```

Every history row written inside keeps the cause (`TaskChangeHistory::getCause()`); the listeners of the events dispatched inside read `TaskChangeContext::current()`. The history shows it only through `history.cause_labeler`, whose text is escaped. `TaskUpdatedEvent::getPreviousValue('priority')`, `getAddedTags()` and `getRemovedTags()` give the values before the write.

### Recurring tasks (since 2.3)

`recurrence.enabled: true` adds the *Repeat* section to the task panel (block `nowo_task_repeat`: text only without `canEditTask()`), the Repeat page and a repeat icon on cards and list rows. The bundle schedules nothing: see [UPGRADING](UPGRADING.md#recurring-tasks-what-a-host-does). Each occurrence is a copy of the latest one (the `TaskCopier` rules), created as the series' author.

| Write path | Asks |
| --- | --- |
| `createOccurrence()` | `QUOTA_CHECK` `task_create` with the tasks it creates; the host checks its own plan and the author first |
| Make recurring, edit, pause, resume, skip, stop | `canEditTask()`; nothing is created by them |

### Deleting and renaming outside the UI (since 2.2)

The UI has no delete task or rename board. A host calling the services can: `TaskBoardEraser::eraseTask($task, $actor)` deletes a task and its subtasks and keeps tracked time, detached (a task that follows a provider is refused); `TaskBoardEditor::update($board, $name, $description, $actor)` keeps the slug and dispatches `BOARD_UPDATED`. Check access first with `TaskBoardResourceAccessChecker`, and validate with `Validation\TaskConstraints`, the rules the forms use.

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
4. Import again whenever you like: since 1.22 an import is an idempotent upsert — rows imported before update their
   task (the source wins for the fields it has, empty fields keep your changes), the same file twice changes nothing,
   and the summary counts created / updated / unchanged / skipped rows. See
   [Re-importing](import/README.md#re-importing-idempotent-upsert-since-122).

CLI:

```bash
php bin/console nowo:task-board:import <board-uuid> /path/to/export.csv --source=clickup_csv
```

Supported `--source` values: `clickup_csv`, `clickup_json`, `jira_csv`, `trello_json`, and since 1.18 the bundle's own
`taskboard_csv` / `taskboard_json` (subtask tree, start dates, Gantt relations: [docs/import/taskboard.md](import/taskboard.md)).

Since 1.18 the import page and the task page have a **How to import** help modal (`manage/_import_help.html.twig`):
what each format imports, the own format's columns and examples. **Import subtasks** on a task (route `task_import`,
editors only) imports a file as that task's subtask tree, with its Gantt relations.

**Export guides (English):** [docs/import/](import/README.md) — step-by-step instructions for [ClickUp](import/clickup.md), [Jira](import/jira.md), and [Trello](import/trello.md).

**Your own help under the source select (since 1.22.1).** Both import forms have a place for the host's help right
under the **Source** select:

- The board import page has an empty `import_help` block. Point `templates.import` at a page that extends the bundle's
  and fills only that block:

  ```twig
  {# templates/tasks/import.html.twig — nowo_task_board.templates.import: 'tasks/import.html.twig' #}
  {% extends '@NowoTaskBoardBundle/manage/import.html.twig' %}

  {% block import_help %}
      <p><a href="{{ path('app_manual', {page: 'import'}) }}">Format and examples</a></p>
  {% endblock %}
  ```
- The **Import subtasks** dialog is included in the task page, so its block cannot be overridden from outside. Both
  forms also render an optional link `{url, label}`: the `help_link` include parameter, or the Twig global
  `nowo_task_board_import_help_link` for every page at once. The label is escaped, and the URL must be http(s) or a path
  on the site (`/…`); anything else becomes `#`.

  ```yaml
  # config/packages/twig.yaml
  twig:
      globals:
          nowo_task_board_import_help_link: { url: '/manual/tasks/import', label: 'Format and examples' }
  ```
- Since 1.24, `anchor_from: source` makes the link's `#anchor` follow the selected import source: `taskboard_json`
  opens `…#taskboard-json` (lowercase, underscores to hyphens; the current fragment of the URL is replaced). The page
  renders the anchor of the selected source and the `nowo-task-board-select-anchor` Stimulus controller updates it when
  the select changes. Default `null`: the URL is used as given.

  ```yaml
  twig:
      globals:
          nowo_task_board_import_help_link: { url: '/manual/tasks/import', label: 'Format and examples', anchor_from: source }
  ```

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

Since 1.24 a checker may implement `TenantAwareTaskBoardAccessCheckerInterface` instead: its
`canCreateBoard(object $user, ?string $tenantKey = null)` receives the tenant key of the request (`tenancy`), so "may
create a board in this project" needs no listener on the create route. The board index asks it too and shows *New
board* only to whoever may create a board there. The bundle's checkers implement it and ignore the tenant; a checker
that only implements `TaskBoardAccessCheckerInterface` is asked without the tenant, as before.

```php
final class ProjectBoardAccessChecker implements TenantAwareTaskBoardAccessCheckerInterface
{
    public function canCreateBoard(object $user, ?string $tenantKey = null): bool
    {
        return $tenantKey !== null && $this->projects->userMayManageTasks($user, $tenantKey);
    }
    // canAccess(), canListBoards() …
}
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

## Task API (since 2.2)

A REST API shaped like ClickUp's, off by default: see [API](API.md).

## Host hooks (since 2.0.0)

```yaml
nowo_task_board:
    tenancy:
        open_single_board: true            # a tenant index opens the only board the user sees; ?boards=1 keeps the list
        tenant_paths:                      # since 2.6.0 an entry may set its own open_single_board (wins over the global one)
            - { path: '/tasks/{tenantSlug}', name_prefix: 'general_', slug_prefix: 'user-', open_single_board: false }
            - { path: '/projects/{tenantSlug}/tasks', name_prefix: 'app_project_', open_single_board: true }
        first_board_provisioner: App\Tasks\FirstBoard   # FirstBoardProvisionerInterface, called on an empty tenant index
    import:
        help_link:
            route: app_manual              # or url
            label: tasks.import.help       # translated with translation_domain
            anchor_from: source            # the #anchor follows the import source
            access_attribute: ROLE_MANUAL  # is_granted() before rendering
            external: auto                 # auto: a new tab for a URL on another host
    templates:
        task_time_before: tasks/_timer.html.twig   # before the Time section of the task page and panel
```

**Chrome a host draws itself.** `templates.page_header: false` leaves the header out of the board, list, Gantt and import pages. `board.toolbar: false` leaves the strip above the board out; its dialogs stay, and any element with `data-nowo-task-board-open="column-set-save"` or `"column-set-apply"` opens them. `task_panel.toolbar: false` leaves the trail and actions out of the panel. Since 2.6.0 the panel root says whether the viewer may edit the task in `data-nowo-task-can-edit="true|false"` (toolbar or not), and with `board.toolbar: false` the board keeps its reorder hint for screen readers.

**Quota.** `TaskBoardEvents::QUOTA_CHECK` asks before a write. `TaskBoardQuotaCheckEvent` holds `action` (`board_create`, `task_create`, `import`, since 2.3 `task_move`), `tenantKey`, `board`, `count` and `actor`; `deny($reason)` refuses it and shows the reason, which you pass translated.

| Write path | Asks | On refusal |
| --- | --- | --- |
| New board | `board_create` | Flash and redirect, or 422 with the form on a dialog request |
| First board on a visit (`single_board`, `first_board_provisioner`) | `board_create` | The empty index |
| New task, inline add | `task_create` | Flash and redirect |
| New subtask | `task_create` | Flash and redirect |
| Import into a board or a task, web and command | `import`, with the tasks the file would create, before anything is written | The reason as the import's error |
| Duplicate (page, or `TaskCopier` called by host code), since 2.3 | `task_create`, with every task the copy creates, before anything is written | The reason on the page; `TaskCopyRefusedException` (`quota`) from code |
| Recurring occurrence (`TaskRecurrenceManager::createOccurrence()`), since 2.3 | `task_create`, with every task it creates, before anything is written | `TaskCopyRefusedException` (`quota`); the host calls `block()` |
| Move to another board (page, or `TaskMover`), since 2.3 | `task_move`, with the open tasks that arrive (none: not asked) | The reason on the page; `TaskCopyRefusedException` (`quota`) from code |
| Provider sync, restoring or reopening a task | No: `SyncLimitCheckerInterface` | See [sync](sync.md) |
| `TaskManager::create()` called by host code | No: the host asks | |

**Tenant list check.** A `TenantAwareTaskBoardAccessCheckerInterface` answers `canListBoards($user, $tenantKey)`. With a tenant, a refusal is a 404 on its index and create route.

**Emoji picker.** `board.icon_form_type: Nowo\UiKitBundle\Form\EmojiPickerType` swaps the icon field. The bundle's constraints still apply.

**Remote dialogs.** TaskBoard answers redirects. Set `nowo_ui_kit.remote_dialog.convert_redirects: true`, and `step_routes` for steps, to answer a dialog request with 204 and the redirect header.

## Layout without forking pages

Since 2.0.0: set `nowo_task_board.web_ui.layout_template` to a layout that renders the UiKit canonical blocks, or leave it null for `nowo_ui_kit.layouts.admin`. See [TEMPLATES](TEMPLATES.md) and [UPGRADING](UPGRADING.md#to-200-uikit-page-contract).

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

## Using it from a DDD / hexagonal application

TaskBoard is a **bounded context with its own model and persistence**: its own Doctrine entities (`Task`,
`TaskBoard`, `BoardColumn`, `TaskComment`, ...), mapped by the bundle on `database.entity_manager`, plus its own UI
and optional API. Keep it beside your domain, not inside it: your domain never references TaskBoard entities, and
TaskBoard never needs your domain classes.

**What the bundle needs from you**

- `user_class`: a Doctrine entity **in the same entity manager** as the TaskBoard tables, with a scalar `getId()`.
  TaskBoard maps creator, member, author and time-entry users as `ManyToOne` to it (rewritten at
  `loadClassMetadata`; no `resolve_target_entities` needed). In a hexagonal app this can be your infrastructure
  user record, not your domain `User`. Labels come from `getDisplayName()`, `getFullName()`, `getEmail()`,
  `getUsername()` or `getUserIdentifier()`; the web UI expects Symfony `UserInterface`.
- Tenants and host objects are plain strings: a board's `tenantKey`, and task links to host objects
  (`objectType` + `objectId`, `TaskLink::forHostObject()`), resolved by your `TaskObjectLinkResolverInterface`.

**Ports you implement** (service ids in config)

| Concern | Interface |
|---|---|
| Who may be assigned | `Assignee\TaskAssigneeProviderInterface` |
| Access | `Security\TaskBoardAccessCheckerInterface`, `TenantAwareTaskBoardAccessCheckerInterface`, `TaskBoardTeamMembershipResolverInterface` |
| Tenancy | `Tenancy\TaskBoardTenantResolverInterface`, `FirstBoardProvisionerInterface`, `Board\BoardScopeProviderInterface` |
| Links to your objects | `Link\TaskObjectLinkResolverInterface` |
| API | `Api\Hook\*Interface` (authenticator, users, workspace, plan gate, ...) |

Policy decisions can also be answered by listening to `TaskBoardEvents::BOARD_ACCESS_CHECK`, `TASK_ACCESS_CHECK`,
`BOARD_LIST_QUERY` and `QUOTA_CHECK`.

**Events you translate** (after flush; listen in your infrastructure layer and map to your own messages):
`TASK_CREATED`, `TASK_UPDATED`, `TASK_MOVED`, `TASK_COMPLETED`, `TASK_REOPENED`, `TASK_MEMBER_ADDED`,
`TASK_COMMENT`, `TASK_MENTION`, `TASK_BOARD_CHANGED`, `TASK_ERASED`, `BOARD_CREATED`, `BOARD_UPDATED`,
`BOARD_EMPTIED`, `BOARD_DELETED`, `API_WRITE`, and the `SYNC_*` events.

```php
#[AsEventListener(event: TaskBoardEvents::TASK_COMPLETED)]
final class TaskCompletedTranslator
{
    public function __construct(private MessageBusInterface $bus) {}

    public function __invoke(TaskCompletedEvent $event): void
    {
        $task = $event->getTask();
        $this->bus->dispatch(new WorkItemCompleted(       // your own message
            taskId: $task->getId(),
            tenantKey: $task->getBoard()->getTenantKey(),
            actorId: (string) $event->getActor()->getId(),
        ));
    }
}
```

**Commands** go through the bundle services (`TaskManager`, `TaskBoardCreator`, `TaskCommentManager`,
`TaskTimeLogger`, `TaskMover`, `TaskBoardEraser`, `TaskBoardEditor`). Wrap them in your own port (e.g.
`TaskBoardGateway`) so your application layer depends on an interface you own. For account erasure, call
`TaskSyncPeopleReassigner::detachUser()` from your erasure flow.

**Known limitations**

- Users must be a Doctrine entity in the bundle's entity manager; an id-only user reference is not available.
- Most write events carry the Doctrine entities (`getTask()`) and the user object, not ids: copy the ids out in
  your listener and do not keep the entities.
- No events yet for archive/restore, comment delete, time entries, dependencies, links, member removal or column
  changes; read `TaskChangeHistory` or listen to Doctrine if you need them.
- The services are `final` and have no interfaces.
- `BOARD_LIST_RESULT`, `TASK_LIST_QUERY`, `TASK_LIST_RESULT`, `TASK_READ_ONLY_RESOLVE` and `MEMBER_LIST_QUERY` are
  declared but never dispatched.

## Board index and default view (since 1.24)

The board index (`templates.index`, `manage/index.html.twig`) is a list page: the header with its actions, one panel with the boards or an empty state, *New board* for whoever may
create a board here (since 2.1.1 one button: in the header when there are boards, else in the empty state), and the **New board**
dialog (`nowo-task-board-dialog`), with a `<noscript>` copy of the form under the list. Blocks for a page extending it:

| Block | Content |
|-------|---------|
| `nowo_ui_page_header` | The whole header (UiKit's), from `page.header`: *New board* when there are boards, *Back* with `dashboard_route` |
| `nowo_ui_list_row` | One board as table cells (`row`, `loop` in context); default `manage/_board_row.html.twig` |
| `nowo_ui_list_after` | After the list: the `<noscript>` New board form |
| `nowo_ui_modals` | The New board dialog |

With no boards, the scaffold draws `page.empty` (the message and, for creators, *New board*) through UiKit's `empty` partial.
Since 2.0.0 `nowo_task_board_toolbar`, `index_actions`, `index_board_item` and `index_empty` are gone from the index
([UPGRADING](UPGRADING.md#to-200-uikit-page-contract)).

Partials for host pages: `manage/_board_list.html.twig` (`boards`: the same items, e.g. in a board switcher dialog;
it prints whatever it gets, so pass boards already filtered with `TaskBoardResourceAccessChecker::filterBoards()`),
`manage/_board_list_item.html.twig` (`board`: name, description, Board / List / Gantt links with icons, each with an
`aria-label` naming the board, in a group named after it) and `manage/_board_create_form.html.twig` (`form`;
`in_dialog` adds *Cancel*; `id_prefix` for a second copy on the page; since 1.26 the form's id is
`<id_prefix>nowo-task-board-create-form`, `with_actions: false` leaves out the action row so a dialog can render it in
its footer with `form="nowo-task-board-create-form"`, and the action row of `in_dialog` carries
`data-nowo-ui-dialog-actions` for a host dialog). Since 2.7.0 *Back* and *Cancel* show `arrow-left` and `x` like
Create's `plus`; `back_icon`, `cancel_icon`, `create_icon` (a Tabler name, or `false`) change them, `step_testid_prefix`
(default `nowo-task-board-create`) renames the `-scopes`, `-next`, `-details`, `-advanced` and `-back` testids and
`form_class` adds classes to the form. `manage/_board_create_actions.html.twig` (the footer for `with_actions: false`)
takes the same icons and `back_testid` / `step_testid_prefix`.

**Invalid New board.** The create action answers `422` with the values and errors: the index page with the dialog open
(and an error flash), or — when the request carries the `board.create_dialog_header` header (a host dialog that posts
with `fetch`) — only `_board_create_form.html.twig`, with `Vary` on that header. A POST without the form still goes
back to the index. `?new=1` opens the dialog on load.

**Default view.** `board.default_view_resolver` names a `TaskBoardDefaultViewResolverInterface` service:
`defaultView($user, $board)` answers `board`, `list` or `gantt` (null = board). A main GET of the plain board route
then redirects to the board's list or Gantt route (the query string is kept, without route variables or `_` keys), except with `?view=board`, an XHR
request, or a same-origin `Referer` under the board's own path (the user chose the board view from another page of
the board). The bundle's Board links (index, list and Gantt view switches) carry `?view=board`.

```yaml
nowo_task_board:
    board:
        default_view_resolver: App\Tasks\PreferredTaskViewResolver
        create_dialog_header: X-Beacon-Dialog
```

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

Since 2.9.0 a column icon may also be **one emoji**, printed as text (`aria-hidden="true"`, the column name stays the
accessible text, with any icon set). `board.column_icon_form_type` gives the dialogs the host's widget instead of the
grid; the bundle validates the value on every write path (form, API, config, column sets): one emoji of at most 16 bytes
or an id of `board.column_icons` / `board.default_columns`. The Beacon setup:

```yaml
nowo_task_board:
    board:
        column_icon_form_type: Nowo\UiKitBundle\Form\EmojiPickerType
        column_icon_form_options: { max_bytes: 16 }
        default_columns:
            - { name: 'To do', color: '#94a3b8', icon: '📝' }
            - { name: 'In progress', color: '#3b82f6', icon: '🚧' }
            - { name: 'Review', color: '#f59e0b', icon: '👀' }
            - { name: 'Done', color: '#22c55e', icon: '✅' }
```

New boards get these icons. For boards that already exist:

```bash
bin/console nowo:task-board:apply-default-column-icons --dry-run          # every active board: what would change
bin/console nowo:task-board:apply-default-column-icons --tenant=project-42 # one tenant's boards
bin/console nowo:task-board:apply-default-column-icons --board=BOARD_ID    # one board (add --tenant to require its tenant)
bin/console nowo:task-board:apply-default-column-icons --board=BOARD_ID --include-archived  # an archived board
```

Runs over every board and per tenant cover active boards only; `--board` skips an archived board (and says so) unless
`--include-archived` is given. An empty `--tenant` (e.g. an unset shell variable) is an error, not "every tenant".

With the default grid, a column whose icon the grid does not offer (such an emoji) shows it as the selected choice in the
edit dialog and keeps it on save; *No icon* removes it.

The command only fills columns with **no** icon, so it is safe to run again and never replaces an icon a user picked.
A column keeps no key of the `default_columns` entry it was created from, so it is matched by its **exact name** (case
and spaces included): a renamed column is left alone. Each change is logged as a `column_update` write (no event, as a
column edit in the dialog).

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

### Previous / next task (since 1.20)

The task page shows where the task sits and steps to its neighbours (`manage/_task_navigation.html.twig`, block
`nowo_task_navigation`, view variable `taskNavigation` built by `Support\TaskNavigator`):

- **Board order** (default): the columns left to right, each column's cards top to bottom — the last card of a column
  steps on to the first of the next one. With `board.top_level_only` only top-level cards count. Position: *3/14 · In
  progress*.
- **List order**: `?from=list` (the list view's title links add it) follows the list's groups, subtasks included, then
  the tasks without a column. The step links keep `from`. The list's client-side filters and sort stay in the browser:
  the page follows the server order; a drawer on the list page walks the rows as filtered and sorted.
- **Subtasks**: a subtask steps through its parent's subtasks (*Subtask 2/5*) and links back up to the parent; a task
  with subtasks offers *First subtask*.
- **Keyboard**: Alt+← / Alt+→ (not inside a multi-line text, where they move by word) and K / J (outside any field);
  leaving unsaved edits of the task form asks first. The ends are disabled steps (`aria-disabled`).

In the task panel only the subtask steps render (`[data-nowo-task-nav-context="panel"]`, also `.nowo-task-nav--panel`), with `data-task-panel-url` on their links.
Since 2.5.0 (TB-11b) the public hooks are `data-nowo-task-nav="view|subtasks"` on each group, `data-nowo-task-nav-position`
on its position text and `data-nowo-task-edit-form` on the task form (the id `nowo-task-edit-form` stays):
a drawer opens them in place, and can use them for its own ‹ › when the open task is not one of its view's. Cards, list
rows and Gantt bars carry `data-title` and `data-column-name` for a drawer's heading and position.

### Task page hook (since 1.25.1)

The full task page wraps its content in `<div class="nowo-task-page" data-task-id="…">`, a stable hook for
integrations (for example the TimeTrack browser extension) to find the open task. The panel fragment keeps its own
`.nowo-task-panel-fragment[data-task-id]` hook and does not carry `.nowo-task-page`. The wrapper has no styles.

### No inline styles

The templates carry no `style=""` (CSP without `'unsafe-inline'`): colours, Gantt positions and depths are CSS custom
properties written as JSON in `data-nowo-task-vars` and set by `task-board.js` through the CSSOM (also inside panels
inserted later). The subtask progress is a native `<progress>`. New semantic classes: `nowo-task-column--colored`,
`nowo-task-column__icon`, `__header`, `__title`, `__name`, `__count`, `__edit`, `__tasks`, `__empty`, `__add-task`,
`nowo-task-column-sets` (`__item`, `__delete`, `__empty`), `nowo-task-dialog` (`--native`, `__header`, `__body`,
`__actions`, `__footer` since 1.26, `__danger` up to 1.25), `nowo-task-progress`, `nowo-task-list__row`, `nowo-task-panel-fragment`.

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
the icons once (`php bin/console ux:icons:import tabler:<name> …`): arrow-left, arrow-right, arrows-sort, building, calendar,
calendar-event, chart-bar, check, chevron-down, chevron-up, corner-left-up, chevron-left, chevron-right, circle, circle-check, circle-dot, clock, clock-play, hourglass,
columns, device-floppy, download, external-link, flag, folder, git-branch, grip-vertical, history, layout-columns, layout-grid, layout-kanban,
help, link, arrows-move, copy, repeat, list, list-tree, message-circle, notes, palette, pencil, plug, plug-connected, plus, refresh, subtask, tags, trash, unlink, upload, user-minus, user-plus,
users, x. With `icon_set: bootstrap-icons` (since 2.9.0) the bundle prints the Bootstrap Icons 1.13 name of each of these
(and of the default `board.column_icons`) where Tabler's name does not exist there, e.g. `device-floppy` → `floppy`,
`circle-check` → `check-circle`, `layout-kanban` → `kanban` (`Support\IconNames::BOOTSTRAP`); a host page still loads
the Bootstrap Icons stylesheet itself. Mapping `create`, `chevron_up` or `chevron_down` in `nowo_ui_kit.action_icons` replaces New board's `plus` and the filter fold's chevrons (since 2.0.2).

Each icon of `board.column_icons` is imported too, since every board page draws them in its column dialogs. The default is the 30 in `Configuration::DEFAULT_COLUMN_ICONS`; set `board.column_icons` to a shorter list to import fewer.

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

**Dialog anatomy and sizes (since 1.26, dialog rule v2).** Every dialog is header (`__header`: `__title` and the ×
`__close`, tinted), body (`__body`, the only part that scrolls) and footer (`__footer __actions`, tinted, **outside the
body**: its submit buttons name their form with `form="…"`). The footer holds Cancel / Close first and **one primary**
last; a secondary destructive action (*Delete column*, *Remove link*) is a danger-outline button on the left
(`__actions--split`); a destructive confirmation (*Unlink*, *Archive anyway*) has the solid danger button as its one
primary. Widths are the kits' shared tokens — `--nowo-dialog-width-md` (default `min(40rem, 100vw - 2rem)`) and, for
`nowo-task-dialog--wide` (add task, people, links, dependency, import help), `--nowo-dialog-width-wide`
(`min(56rem, 100vw - 2rem)`); the height follows the content, capped at `--nowo-dialog-height-md` / `-wide`
(`min(36rem, 100dvh - 2rem)` / `min(52rem, 100dvh - 2rem)`) and never taller than the viewport minus 2rem. The bundle
sets no size of its own: a host sets the tokens once on `:root` for every kit.

The footer buttons read the UiKit theme slots `btn_secondary` (Cancel / Close), `btn_primary`, `btn_danger`
(secondary destructive) and `btn_danger_solid` of the theme bundle `NowoTaskBoardBundle` through the Twig function
`nowo_task_board_btn_class(slot)`, so a host restyles them in `nowo_ui_kit.theme.bundles.NowoTaskBoardBundle.classes`;
without UiKit's theme extension (UiKit 1.x) they keep the Bootstrap classes of 1.25 (`btn btn-outline-secondary`,
`btn btn-primary`, `btn btn-outline-danger`, `btn btn-danger`).

## Comments and @mentions (since 1.16)

The activity stream of the task detail and panel holds the **comments** interleaved with the history (time order),
with an *All activity / Comments* toggle, and closes with a comment box. A comment is plain text (line breaks kept,
no HTML or Markdown), up to 5000 characters. Its author edits it in place; its author or an editor of the task deletes
it (it stays as "Comment deleted"). Board cards and list rows show the comment count.

**Who comments.** Everyone who may read the task, unless `comments.readers_can_comment: false` (then only the people
who may edit it). The write routes check CSRF and `TASK_ACCESS_CHECK` like every other task write.

**@mentions.** Typing `@` in the box lists the people who can read the task; ↑ ↓ move, Enter or Tab pick, Escape
closes (a combobox: `aria-expanded`, `aria-activedescendant`). The server keeps a mention only for a candidate whose
"@Name" is still in the text; the rest stays plain text. Mentions show as chips and a comment that mentions the viewer
is highlighted. The candidates come from your `TaskMentionCandidatesResolverInterface`:

```php
final readonly class ProjectMentionCandidates implements TaskMentionCandidatesResolverInterface
{
    public function candidates(Task $task): array
    {
        return array_map(
            static fn (User $user): MentionCandidate => new MentionCandidate((string) $user->getId(), $user->getDisplayName(), $user),
            $this->readersOf($task->getBoard()),
        );
    }
}
```

**Events** (after flush): `TaskBoardEvents::TASK_COMMENT` (`TaskCommentEvent`: `getComment()`, `getAction()` created
/ edited) and `TaskBoardEvents::TASK_MENTION` (`TaskMentionEvent::getMentionedUsers()`: the people mentioned — on an
edit only the ones it adds, never the author). The bundle sends no notification: notify from these events.

**Theming**: `--nowo-task-mention-bg`, `--nowo-task-mention-ink` (the chip), `--nowo-task-mention-me` (the highlight).

## Dependencies, time and Gantt scales (since 1.15)

One new column (`task_board_tasks.start_at`, see UPGRADING); the dependencies (`task_board_task_dependencies`) and the manual time entries
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

## Provider connections and archived tasks (since 1.23)

A board can follow a list of another tool (ClickUp first): the first run imports it, later runs create, update,
archive and restore its tasks. It is opt-in (`sync.enabled`) and the host keeps the tokens. Everything — hooks,
configuration, the field and conflict rules, the mass-archive guard, files, background runs, the command, the UI and
the events — is in [sync.md](sync.md).

**What a linked board brings (since 1.27.0).** Managers map remote people to host users on the *People* page of the
connection; a candidate of the same name is suggested, never selected for them. The task panel shows the typed custom fields and a read-only *From ClickUp* section: custom task id
(also found by the list search), points, done and closed dates, location, estimate per assignee, recurrence and time in
status. Linked tasks appear as *related* relations, and archived tasks are imported archived. After each run the
connection page lists every field of the provider's answers: imported, skipped (with the reason) or *Not imported yet*.
What a run could not read is listed as *Not read* or *Unavailable*. Hosts choose which custom field types are imported
(`sync.custom_fields.exclude_types`) and own erasure and access requests: [SECURITY](SECURITY.md#personal-data-in-synced-tasks-rgpd--lopdgdd).
**Tracked time is never deleted by a sync, or when a task, a board or a user is deleted**: the entries stay, detached. A manual
entry is removed only by its author or a board manager. Removing
imported time is an explicit manager action in TimeTrack.

**Archived tasks** (every host): `Task::archive()` / `restore()` / `isArchived()`. An archived task is hidden from the
board, the list, the Gantt and the counts (`findByBoard()`, `findByBoardWithin()`, `countByBoard()`); the list view shows
the archived tasks in a folded "Archived tasks" section with *Restore* for editors (route `task_restore`, CSRF token id
`task_board_task_restore`), and an archived task opened by URL says so. File imports match archived tasks too (they are
updated, never duplicated, and stay archived).

## Task member pictures (since 1.23)

The member chips (cards, list, task members, the members dialog, comments, history) show the initial. To show
pictures, alias `Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface` to your service:

```php
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface;
use Nowo\TaskBoardBundle\Avatar\TaskMemberKey;

final class ProfilePictures implements TaskMemberAvatarResolverInterface
{
    public function urlFor(object $user, int $size): ?string
    {
        return $this->urlsFor([$user], $size)[TaskMemberKey::of($user)] ?? null;
    }

    /** One call per page: load the pictures of all these users in one query. */
    public function urlsFor(array $users, int $size): array
    {
        $urls = [];
        foreach ($this->pictures->forUsers($users) as $user => $fileId) {   // your storage
            $urls[TaskMemberKey::of($user)] = '/files/' . $fileId . '/s64';  // stable, access-checked when fetched
        }

        return $urls;
    }
}
```

```yaml
# config/services.yaml
Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface:
    alias: App\Tasks\ProfilePictures
```

- The bundle asks for size 32 (its chips are 24 and 32 px), once per page, after its access checks: the board and the
  list resolve only the 3 chips each card shows; the task page and panel resolve its members, comment authors and
  history users. Key your answer with `TaskMemberKey::of($user)`.
- Allowed URLs: `https://host[:port]/…` without user info, or a path on the site starting with one `/`. Anything else
  is dropped and the initial stays: `http:`, `data:` (also `data:image`), `javascript:`, `blob:`, `//…`, relative paths
  (`avatars/1.png`, `./a.png`), IPv6 literals, a port that is empty or outside 1–65535, backslashes, quotes, `<`, `>`,
  backticks, control characters or white space (a trailing line feed too), and more than 2048 bytes (UTF-8). An
  `asset()` package with a protocol-relative `base_urls` gives `//…` and falls back too. **Never pass a URL a user
  typed:** every viewer's browser fetches it. Return a **stable** URL: a page that is open for a while asks for it again (a short `private, max-age` on a
  redirect to a signed link is fine).
- A picture that fails to load (403, 404, expired link) goes back to the initial: `task-board.js` has one capturing
  `error` listener and does not retry that URL while the page is open. No inline handler, so CSP stays strict. Your
  `img-src` must allow the origins your URLs point or redirect to.
- Accessibility: the `<img>` is always `alt=""`. Card and list chips (no name next to them) are `role="img"` with the
  member's name as `aria-label`; chips next to a printed name are `aria-hidden`.
- Draw your own chip: override `templates/bundles/NowoTaskBoardBundle/manage/_task_member_avatar.html.twig` (variables
  `user`, `class`, `size`, `labelled`) or call `nowo_task_board_member_avatar_url(user)` in your templates.

## Task numbers and calendar (since 2.1)

Both are opt-in, and neither needs the other.

```yaml
nowo_task_board:
    task_numbers:
        enabled: true
        scope: tenant   # or board
    calendar:
        enabled: true
```

**Numbers.** Every task gets `#123` when it is persisted, whichever way it was created. Print it with `nowo_task_board_number(task)` (null: option off, or no number yet); read it with `Task::getNumber()`. A provider's custom id and an import's external id stay separate references, and never become the number. To find a task by number in your own search:

```php
$tasks = $taskNumberLedger->findInScopes($scopeKeysTheViewerMayRead, 123); // current numbers and aliases, one query
```

Check that the viewer may read what it returns: the ledger does not. The route `task_by_number` does: `/projects/<slug>/tasks/n/123` redirects to the task page; with `scope: board`, or a board outside any tenant, add `?board=<board id>`.

**Calendar.** `/board/{boardId}/calendar?mode=month|week|list&date=Y-m-d`, with the filters `assignee`, `priority`, `tag`, `column`, `completed=1` and `subtasks=1`. A link reproduces the view. Completed tasks and subtasks are hidden unless asked for. A task shows as a bar with both dates, on its day with one, and not at all with none.

Editors change dates four ways, all through `task_schedule` (`start`, `due`, `_token`; an empty date clears it, and both are required): drag the body, drag an edge, *Reschedule*, or `TaskManager::reschedule()` from code. The server checks `canEditTask()` on every write: read-only answers 403, out of reach 404.

## Translations

Domain: `NowoTaskBoardBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoTaskBoardBundle/` or `translations/NowoTaskBoardBundle.en.yaml`.
