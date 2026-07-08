# Feature Specification: TaskBoardBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## User Scenarios & Testing

### User Story 1 — Kanban board (Priority: P1)

As a team member, I create a board with columns and drag tasks between columns on the kanban view.

**Independent Test**: Open `/tools/task-board/board/{id}` → add column/task → drag task → column change persisted.

**Acceptance Scenarios**:

1. **Given** access roles satisfied, **When** board loads, **Then** `TaskBoardManageController` renders columns with tasks grouped by `BoardColumn`.
2. **Given** Stimulus `board-controller`, **When** card dropped, **Then** POST updates task column via `TaskManager`.

---

### User Story 2 — Task detail, links, and members (Priority: P1)

As a developer, I attach MR/PR links, assign members with roles, and record change history on a task.

**Acceptance Scenarios**:

1. **Given** GitLab MR URL, **When** link added, **Then** `GitLabMergeRequestLinkParser` may enrich metadata; `TaskLinkAttacher` persists typed `TaskLink`.
2. **Given** assignee/reviewer/watcher roles, **When** members saved, **Then** `TaskMemberAssigner` stores `TaskMember` rows; `TaskChangeRecorder` logs changes.

---

### User Story 3 — List, Gantt, import (Priority: P2)

As a project lead, I filter tasks in list view, inspect dependencies in Gantt, and import from ClickUp/Jira/Trello.

**Acceptance Scenarios**:

1. **Given** list view, **When** filters applied, **Then** `list-filter-controller` + utils narrow DOM rows client-side; server query respects `TaskAccessGuard`.
2. **Given** CSV/JSON export file, **When** import form or `nowo:task-board:import` runs, **Then** `TaskImportOrchestrator` selects importer by source and reports `TaskImportResult`.
3. **Given** task dependencies, **When** Gantt renders, **Then** `TaskGanttBuilder` supplies timeline items/links to `gantt-controller`.

---

### User Story 4 — Integration & ACL (Priority: P2)

As an integrator, I configure routes, table prefix, templates, and event-driven ACL; optionally wire TimeTrack providers.

**Acceptance Scenarios**:

1. **Given** `TaskBoardRouteLoader`, **When** routes imported with `type: nowo_task_board`, **Then** paths/names reflect `nowo_task_board.routes.*` config.
2. **Given** `TaskAccessCheckEvent`, **When** listener denies, **Then** task/board hidden from queries.
3. **Given** TimeTrack installed, **When** timer stops, **Then** `TimeSpentAggregatorListener` updates task `total_time_seconds`; bridge services register via `services_timetrack.yaml`.

---

## Requirements

### Bundle & platform

- **FR-BUNDLE-001**: `TaskBoardBundle` registers extension alias `nowo_task_board` and `TwigPathsPass`.
- **FR-CFG-001**: `Configuration` MUST require `user_class`; define `table_prefix`, team resolver, routes, security roles, templates, import settings.
- **FR-CFG-002**: `TaskBoardExtension` loads services and optional TimeTrack bridge YAML.
- **FR-DI-001**: Core `services.yaml` wires repositories, services, controller, forms, route loader.
- **FR-DI-002**: `services_timetrack.yaml` registers TimeTrack bridge when package present.
- **FR-TWIG-001**: `TwigPathsPass` registers `NowoTaskBoardBundle` view namespace with app override support.
- **FR-ROUTE-001**: `TaskBoardRouteLoader` exposes index, board, list, gantt, task, import routes from config.
- **FR-DOCTRINE-001**: `TaskBoardMetadataListener` applies configurable table prefix to all bundle entities.

### Domain model

- **FR-ENTITY-001 … FR-ENTITY-011**: Doctrine entities for board, column, task hierarchy, links, dependencies, documents, members, time entries, teams — see [`code-inventory.md`](code-inventory.md).
- **FR-ENUM-001**: `TaskPriority`, `TaskStatus`, `TaskLinkType`, `TaskDependencyType`, `TaskMemberRole`, `TeamRole`, `TaskChangeType`.
- **FR-DTO-001**: Form DTOs for board/column/task/import/link/member and Gantt/navigation structures.
- **FR-VO-001**: `Uuid` value object for identifiers.

### Persistence

- **FR-REPO-001**: Repository interfaces for each aggregate.
- **FR-REPO-002**: Doctrine ORM implementations with board-scoped queries.

### Services

- **FR-SVC-001**: `BoardColumnManager` — column CRUD and ordering.
- **FR-SVC-002**: `TaskAccessGuard` — dispatches access events, enforces grants.
- **FR-SVC-003**: `TaskBoardCreator` — board bootstrap.
- **FR-SVC-004**: `TaskChangeRecorder` — audit trail rows.
- **FR-SVC-005**: `TaskGanttBuilder` — Gantt DTO graph.
- **FR-SVC-006**: `TaskLinkAttacher` — typed external links.
- **FR-SVC-007**: `TaskManager` — task CRUD, column moves, status/priority updates.
- **FR-SVC-008**: `TaskMemberAssigner` — member roles on tasks.

### Security & events

- **FR-SEC-001 … FR-SEC-004**: Access checker and team membership resolver interfaces + default implementations.
- **FR-EVT-001**: `TaskBoardEvents` constants.
- **FR-EVT-002**: Query/access events (`BoardListQueryEvent`, `TaskAccessCheckEvent`, etc.).

### Import

- **FR-IMPORT-001**: `TaskImportOrchestrator` coordinates source detection and persistence.
- **FR-IMPORT-002**: Importer interfaces and `TaskImportSource` enum.
- **FR-IMPORT-003**: `NullTaskImportUserResolver` default.
- **FR-IMPORT-004**: CSV/delimited parsers and field mapper.
- **FR-IMPORT-005**: ClickUp, Jira, Trello importers.
- **FR-IMPORT-006**: Import result DTOs.
- **FR-CLI-001**: `nowo:task-board:import` console command.

### HTTP UI

- **FR-CTRL-001**: `TaskBoardManageController` — all manage actions (board, list, gantt, task CRUD, import UI).
- **FR-FORM-001**: Symfony form types for board, column, task, import, link, member.
- **FR-TPL-001**: Twig layout, manage pages, modals, partials (23 templates).
- **FR-I18N-001**: Translation catalogs (`de`, `en`, `es`, `fr`, `it`, `nl`, `pt`).

### Frontend (Stimulus + Vite)

- **FR-UI-001**: `task-board.ts` entry registers controllers and loads CSS.
- **FR-UI-002**: `board-controller` — kanban drag-and-drop.
- **FR-UI-003**: `gantt-controller` — timeline interactions.
- **FR-UI-004**: `list-filter-controller` + `list-filter-utils` — table filtering.
- **FR-UI-005**: `subtask-tree-controller` — nested task tree.
- **FR-UI-006**: `task-status-controller` — inline status updates.
- **FR-UI-007**: Modal controllers (add/edit column/task, links, members, priority).
- **FR-UI-008**: Remaining Stimulus controllers wired by manage templates.
- **FR-UI-010**: `task-board.css` styles.
- **FR-LEGACY-001**: Published `Resources/public/js|css` fallbacks.

### TimeTrack bridge

- **FR-BRIDGE-001**: `TaskBoardTaskProvider`, `TaskBoardTeamContextProvider` implement TimeTrack integration interfaces.
- **FR-BRIDGE-002**: `TimeSpentAggregatorListener` aggregates elapsed time onto tasks.
- **FR-SUP-001**: `SlugGenerator`, `UserIdResolver`, `GitLabMergeRequestLinkParser`.

---

## Success Criteria

- **SC-001**: 147/147 production files mapped (`*.test.ts` excluded).
- **SC-002**: Config keys match `Configuration.php` and Flex recipe defaults.
- **SC-003**: PHPUnit + Vitest + PHPStan pass in CI.
- **SC-004**: Demo with TimeTrack: board + timer + aggregated time on task.

---

## Explicit non-goals

- Native mobile clients.
- Real-time multi-user cursors on kanban (server push).
- Built-in email notifications (host app responsibility).

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `composer qa` |
| Demo + TimeTrack | `make -C TimeTrackBundle/demo up-symfony8` |
| Inventory | `find src -type f ! -name '*.test.ts' \| wc -l` |
