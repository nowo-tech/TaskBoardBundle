# Configuration

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
        layout: '@NowoTaskBoardBundle/layout.html.twig'
        index: '@NowoTaskBoardBundle/manage/index.html.twig'
        board: '@NowoTaskBoardBundle/manage/board.html.twig'
        list: '@NowoTaskBoardBundle/manage/list.html.twig'
        gantt: '@NowoTaskBoardBundle/manage/gantt.html.twig'
        task: '@NowoTaskBoardBundle/manage/task.html.twig'
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

## Assets

The bundle ships Stimulus controllers built to `src/Resources/public/js/task-board.js` (asset package `nowo_task_board`, installed under `public/bundles/taskboard/`).

Rebuild after changes:

```bash
pnpm install && pnpm run build
```
