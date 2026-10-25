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

## Translations

Domain: `NowoTaskBoardBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoTaskBoardBundle/` or `translations/NowoTaskBoardBundle.en.yaml`.
