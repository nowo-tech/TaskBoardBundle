# Usage

## Manage UI

Authenticated users open the manage UI (default `/tools/task-board`):

- **Index** — list active boards and create new boards (default columns: To do, In progress, Done).
- **Kanban board** — drag-and-drop tasks between columns; create tasks and columns.
- **List view** — filterable task table with status, priority, assignee, and due date.
- **Gantt view** — timeline with task bars and blocking dependencies.
- **Task detail** — description, subtasks, members, links, change history, and aggregated time.

## Task operations

| Action | Route key | Description |
|--------|-----------|-------------|
| Move column | `task_move` | Drag task to another column (POST) |
| Advance / regress | `task_advance` | Move to next/previous column |
| Add link | `task_link` | Attach external URL (GitLab MR URLs are parsed) |
| Add member | `task_member` | Assign team member with role |
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

## Custom access control

Two layers:

| Layer | Interface / event | Question |
|-------|-------------------|----------|
| Route features | `TaskBoardAccessCheckerInterface` | Can the user open manage UI, create boards, or list boards? |
| Per board/task | `BoardAccessCheckEvent`, `TaskAccessCheckEvent` | Can the user view or edit this board/task? |

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
└── layout.html.twig
```

Or configure paths in `nowo_task_board.templates.*`.

## Translations

Domain: `NowoTaskBoardBundle`. Bundled locales: **en**, **es**, **de**, **fr**, **it**, **nl**, **pt**. Override in `translations/bundles/NowoTaskBoardBundle/` or `translations/NowoTaskBoardBundle.en.yaml`.
