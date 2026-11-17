# Feature Specification: TaskBoardBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active (refreshed 2026-11-01 for releases 1.10–1.21)  
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

### User Story 5 — Tenants, comments, planning (Priority: P2, releases 1.10–1.21)

As a host integrator and a team member, I serve boards per tenant, discuss tasks with @mentions, reuse column sets,
plan with dependencies, estimates and time entries, import subtasks, and move between tasks without going back to
the board.

**Acceptance Scenarios**:

1. **Given** `tenancy.enabled` and a tenant path, **When** a route of another tenant's board or task is requested,
   **Then** it answers 404 (`TaskBoardTenantContext::owns()`), and every manage route also exists under the tenant path.
2. **Given** a reader of a task and `comments.readers_can_comment`, **When** they comment with an @mention of a
   candidate, **Then** the comment is stored, a `TaskMentionEvent` is dispatched, and only the author edits it.
3. **Given** a board with more than `board.max_tasks` tasks, **When** the board, list or Gantt page renders, **Then**
   it loads the first `board.max_tasks` cards and says "Showing N of M tasks".
4. **Given** a task page, **When** previous / next is used, **Then** `TaskNavigator` moves in the board (or list)
   order and among the parent's subtasks.

---

## Requirements

### Bundle & platform

- **FR-BUNDLE-001**: `TaskBoardBundle` registers extension alias `nowo_task_board` and `TwigPathsPass`.
- **FR-CFG-001**: `Configuration` MUST require `user_class`; define `table_prefix`, team resolver, routes, security roles, templates, import settings.
- **FR-CFG-002**: `TaskBoardExtension` loads services and optional TimeTrack bridge YAML.
- **FR-DI-001**: Core `services.yaml` wires repositories, services, controller, forms, route loader.
- **FR-DI-002**: `services_timetrack.yaml` registers TimeTrack bridge when package present.
- **FR-TWIG-001**: `TwigPathsPass` registers `NowoTaskBoardBundle` view namespace with app override support.
- **FR-ROUTE-001**: `TaskBoardRouteLoader` exposes index, board, list, gantt, task, import routes from config. Since 2.21.1 (REQ-SF-004) the routes are `#[Route]` attributes on the controllers, read by the loader, which still applies the configured names / paths, `route_prefix`, feature switches and tenant copies; the collection is pinned against 2.21.0.
- **FR-DOCTRINE-001**: `TaskBoardMetadataListener` applies configurable table prefix to all bundle entities.
- **FR-DOCTRINE-002**: Flushing repositories and import orchestration recover a closed EntityManager via `RecoveringFlusher` / `ManagerRegistry` so FrankenPHP workers without kernel reset stay usable after a failed flush; security/timer paths refresh managed entities before deciding or mutating.

### Domain model

- **FR-ENTITY-001 … FR-ENTITY-011**: Doctrine entities for board, column, task hierarchy, links, dependencies, documents, members, time entries, teams — see [`code-inventory.md`](code-inventory.md).
- **FR-ENTITY-012**: `ColumnSet` — named list of columns, with its creator and an optional scope key (tenant), offered to the boards of that scope (since 1.11).
- **FR-ENTITY-013**: `TaskComment` — body, mentions, edited / soft-deleted timestamps (since 1.16).
- **FR-ENUM-001**: `TaskPriority`, `TaskStatus`, `TaskLinkType`, `TaskDependencyType`, `TaskMemberRole`, `TeamRole`, `TaskChangeType`, `GanttScale`, `WeekStart`.
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
- **FR-SEC-005**: `TaskBoardResourceAccessChecker` authorizes per board / task in the manage UI (creator, team member or manage role by default); listeners on `BoardAccessCheckEvent` / `TaskAccessCheckEvent` may override the decision.
- **FR-EVT-001**: `TaskBoardEvents` constants.
- **FR-EVT-002**: Query/access events (`BoardListQueryEvent`, `TaskAccessCheckEvent`, `ColumnSetQueryEvent`, `ColumnSetAccessCheckEvent`, etc.).
- **FR-EVT-003** (since 1.10): events after writes (`TaskCreatedEvent`, `TaskUpdatedEvent`, `TaskMovedEvent`, `TaskCompletedEvent`, `TaskReopenedEvent`, `TaskMemberAddedEvent`; since 1.16 `TaskCommentEvent`, `TaskMentionEvent`), dispatched after the flush.

### Releases 1.10–1.21

- **FR-TENANT-001** (1.10): `tenancy` — the tenant of the request (`TaskBoardTenantContext`, `TaskBoardTenantResolverInterface`, default `SlugTaskBoardTenantResolver`), a copy of every manage route per `tenancy.tenant_paths` entry (`TaskBoardRouteLoader`, `TenantPaths`), URL generation that keeps the request's `tenantSlug` (`TenantPathUrlGenerator`), boards and tasks of another tenant answer 404, optional `single_board`.
- **FR-ASSIGNEE-001** (1.10): `assignee_provider` (`TaskAssigneeProviderInterface`) restricts who may be a member of a task on a board; others are refused (`UnassignableUserException`).
- **FR-LINK-001** (1.10): links to host objects (`TaskLinkType::HostObject`) resolved by tagged `TaskObjectLinkResolverInterface` services into `TaskObjectBadge`s.
- **FR-TREE-001** (1.10): subtask tree of a board in one query (`TaskTree`, `SubtaskProgress`); `board.top_level_only` shows parents only with a done / total counter.
- **FR-COLSET-001** (1.11): column sets — save a board's columns, add a set's columns to a board, choose a set for a new board, delete (creator or manage role, `ColumnSetAccessCheckEvent`); column icon, colour, rename and delete.
- **FR-PANEL-001** (1.11): `task_panel` — the task editing fragment without layout for side drawers; its writes come back to a local `_target_path` or answer the refreshed fragment.
- **FR-DEP-001** (1.15): task dependencies (`TaskDependencyType`: blocks, blocked by, related, duplicates) inside one board; self, duplicate, cross-board and cyclic dependencies are refused (`InvalidTaskDependencyException`).
- **FR-TIME-001** (1.15): manual time entries per task (`TaskTimeLogger`), CSRF-protected add / remove by editors.
- **FR-TIME-002** (1.15): estimate vs spent deviation of a task and of its subtasks (`TaskDeviation`).
- **FR-GANTT-001** (1.15): Gantt scales (hours, days, weeks, months), week start from config or a `WeekStartResolverInterface`, dependency arrows.
- **FR-COMMENT-001** (1.16): comments on a task — readers too unless `comments.readers_can_comment: false`; the author edits, the author or an editor deletes (soft delete); mentions are validated against the candidates and dispatched once.
- **FR-COMMENT-002** (1.16): @mention candidates (`TaskMentionCandidatesResolverInterface`, default `DefaultTaskMentionCandidatesResolver`: assignable users, else creator and members).
- **FR-IMPORT-007** (1.18): the bundle's own CSV / JSON format (`TaskBoardCsvImporter`, `TaskBoardJsonImporter`, `TaskBoardRowMapper`) and importing a file as subtasks of a task, with their dependencies (editors only).
- **FR-TEXT-001** (1.19): comments and plain-text descriptions render through `TaskTextRendererInterface` (default `PlainTaskTextRenderer`: escaped text with line breaks); hosts alias it to their own renderer and own its output.
- **FR-NAV-001** (1.20): previous / next task in the board or list order and among the parent's subtasks (`TaskNavigator`, `TaskNavigation`, `TaskPosition`).
- **FR-DUR-001** (1.20): duration estimates entered and shown as "1d 2h 30m" (`MinutesToDurationTransformer`, `nowo_task_board_duration`).
- **FR-PERF-001** (1.21): board, list, Gantt and task pages load at most `board.max_tasks` tasks (members fetched with them), print "Showing N of M tasks" when capped, and the task page fetches its relations in a fixed number of queries (`BoundedTaskRepositoryInterface`, `TaskWindow`).
- **FR-OBS-001** (1.21): writes, refused CSRF tokens and imports are logged through the injected `LoggerInterface` with ids and counts only (no personal data).
- **FR-CLOCK-001** (1.21): time-dependent services take `ClockInterface` (`TaskGanttBuilder`, the controller's default time-entry day).

### Provider sync 1.27.0 (older sync behaviour: `docs/sync.md`)

- **FR-SYNC-PEOPLE-001** (1.27): People page and step of a connection: every remote person with a host-user select fed by the host's candidates, pre-filled by e-mail then name, saved only by a manager (`canManageBoardConnection` on every board of the directory); a saved change re-applies to imported tasks within a time budget and resumes after runs; `PeopleMappingChangedEvent`.
- **FR-SYNC-FIELD-001** (1.27): typed custom fields per board and values per task, read-only, in the task panel and the list search; unknown types and misfits stay as text; `sync.custom_fields.mode`.
- **FR-SYNC-COMMENT-001** (1.27): threaded replies, files under the comment, action items (assignee, resolved) and remote edit date.
- **FR-SYNC-DETAIL-001** (1.27): custom id (searchable), points, done / closed dates (completion date), time flags, location, estimates per assignee, recurrence and time in status (bounded by `sync.status_history.max_calls_per_run`), read-only in `task_board_sync_items.remote_meta`; linked tasks as `related` relations; archived tasks imported archived (`sync.import_archived`).
- **FR-SYNC-PRIVACY-001** (1.27): remote people in read-only JSON are references (`PersonToken`), never names or e-mails; `anonymise()` is enough to remove a name from every view.
- **FR-SYNC-PRIVACY-002** (1.27): `sync.custom_fields.exclude_types` (default none) and `SyncFieldValueEraser` for erasure and access of field values; files of comments are tracked, so erasure names them.
- **FR-SYNC-REPORT-001** (1.27): a per-key completeness report after each run (`FieldCoverageProviderInterface`), covering every task read, at most 200 keys per run; what could not be read is shown as *Not read* or *Unavailable*, never as imported.
- **FR-SYNC-BUDGET-001** (1.27): reply requests (`sync.comments.max_reply_calls_per_run`), task details (`sync.watchers.max_detail_calls_per_run`) and time in status are bounded per run; a failed page defers every task not finished.
- **FR-SYNC-VIEW-001** (1.27): the list reads custom fields and custom ids only for the rows drawn, never `remote_meta`.
- **FR-SYNC-TIME-001** (owner rule): deleting a task, a board or a user keeps all tracked time (manual and imported), detached, with the user's name until it is forgotten; a sync never deletes it; a manual entry is removed only by its author or a board manager (a manager alone when the author is gone); imported time is deleted only by an explicit manager action in TimeTrack.

### Import

- **FR-IMPORT-001**: `TaskImportOrchestrator` coordinates source detection and persistence.
- **FR-IMPORT-002**: Importer interfaces and `TaskImportSource` enum.
- **FR-IMPORT-003**: `NullTaskImportUserResolver` default.
- **FR-IMPORT-004**: CSV/delimited parsers and field mapper.
- **FR-IMPORT-005**: ClickUp, Jira, Trello importers.
- **FR-IMPORT-006**: Import result DTOs.
- **FR-IMPORT-008** (1.22): every import is an idempotent upsert for every source. A row is matched on (import scope,
  source id) stored on the task link (`task_board_task_links.import_source` + `external_id`); the scope is the tool
  (`clickup`, `jira`, `trello`; CSV and JSON of one tool share it) or, for the bundle's own format, `taskboard` /
  `taskboard@<task id>` (file-local ids). A match is updated (`ImportedTaskUpdater`: the source wins for every field it
  has a value for, empty fields keep the application's value; parents follow the source's parent row; dependencies are
  only added), anything else is created. Importing the same file twice changes nothing and `TaskImportResult` reports
  created / updated / unchanged / skipped.
- **FR-IMPORT-009** (1.22): rows without an id are matched by a deterministic fingerprint (scope, parent, normalized
  title, source creation date; the n-th identical row gets `-n`) and the import warns that they are.
- **FR-IMPORT-010** (1.22): cross-board rule (board imports) — a source id already imported into another board of the same tenant
  (`TaskImportLinkLookupInterface`, `DoctrineTaskImportLinkLookup`) is skipped with a warning, never moved or copied;
  fingerprint rows of those tools follow the same rule; the own format is board- / task-local.
- **FR-IMPORT-011** (1.22): only links an import wrote (`import_source`) are matched; the link form cannot change an
  import link's external id; pre-1.22 links need the documented one-off backfill.
- **FR-IMPORT-012** (1.22): ClickUp CSV / JSON map start date, creation date (the start of a new task without a start
  date, on create only), dependencies (Dependencies / Waiting On →
  blocked by, Blocking → blocks; API `dependencies`) and assignees by name; Jira CSV maps start date, Created, "Blocks"
  issue links and names; Trello maps `start` and members; names resolve through `TaskAssigneeProviderInterface` by
  display name when exactly one user matches (`ImportAssigneeResolver`), else a warning. Dates read millisecond and
  second timestamps, ISO, day-first / month-first numeric and ClickUp / Jira text formats deterministically; CSV cells
  may span lines, a BOM is dropped and repeated headers join their values.
- **FR-IMPORT-013** (1.22): `TaskImportOptions::$skipExisting`, `TaskImportFormData::$skipExisting` and the command's
  `--force` are deprecated and ignored; the import form has no "skip existing" checkbox.
- **FR-IMPORT-014** (1.22, audit DL): an import updates, moves, re-assigns or links only tasks the actor may edit
  (`TaskBoardResourceAccessChecker::canEditTask()`); others are skipped and counted without titles. An import into a
  task uses scope `<provider>@<task id>` for every source. Warnings name no other board and no stored title; e-mail
  assignees respect `canBeAssigned()`.
- **FR-IMPORT-015** (1.22, audit DL): `import.max_rows` / `import.max_cell_length` limits, linear de-duplication,
  iterative row ordering, exact ancestor check on moves, dates bounded to 1970-2200.
- **FR-CLI-001**: `nowo:task-board:import` console command.

### HTTP UI

- **FR-CTRL-001**: `TaskBoardManageController` — all manage actions (board, list, gantt, task CRUD, import UI).
- **FR-FORM-001**: Symfony form types for board, column, task, import, link, member, priority, list filters, column sets, dependencies, time entries and comments (FormKit profile `task_board`).
- **FR-FORM-002**: `CsrfActionType` — root-less CSRF-only form of the bare POST actions (REQ-TWIG-005).
- **FR-TPL-001**: Twig layout, manage pages, modals, partials (44 templates).
- **FR-I18N-001**: Translation catalogs (`de`, `en`, `es`, `fr`, `it`, `nl`, `pt`).

### Frontend (Stimulus + Vite)

- **FR-UI-001**: `task-board.ts` entry registers controllers and loads CSS.
- **FR-UI-002**: `board-controller` — kanban drag-and-drop.
- **FR-UI-003**: `gantt-controller` — timeline interactions.
- **FR-UI-004**: `list-filter-controller` + `list-filter-utils` — table filtering.
- **FR-UI-005**: `subtask-tree-controller` — nested task tree.
- **FR-UI-006**: `task-status-controller` — inline status updates.
- **FR-UI-007**: Modal controllers (add/edit column/task, links, members, priority).
- **FR-UI-008**: Remaining Stimulus controllers and helpers wired by manage templates (activity filter, mentions, pickers, option filters, task navigation keys, time utilities, CSS variables, storage).
- **FR-UI-010**: `task-board.css` styles.
- **FR-LEGACY-001**: Published `Resources/public/js|css` fallbacks.

### TimeTrack bridge

- **FR-BRIDGE-001**: `TaskBoardTaskProvider`, `TaskBoardTeamContextProvider` implement TimeTrack integration interfaces.
- **FR-BRIDGE-002**: `TimeSpentAggregatorListener` aggregates elapsed time onto tasks.
- **FR-SUP-001**: `SlugGenerator`, `UserIdResolver`, `GitLabMergeRequestLinkParser`.

---

### Additions 1.23–2.20

- **FR-API-001**: Task API v2 (spec 213, 2.2+): ClickUp-compatible REST endpoints (`Api/Controller`, `Api/Operation`, `Api/Serializer`, `Api/Value`, `Api/Repository`) behind bearer-token principals and capabilities (`Api/*` value types); thin controllers, one operation per request, bounded queries.
- **FR-API-002**: API security and idempotency: `TaskApiGuard` checks every id-based target per request, `TaskApiRequestListener` resolves the principal, `Idempotency-Key` writes are kept 24 h (`TaskApiIdempotencyRecord`, `DoctrineTaskApiIdempotencyStore`, `TaskApiIdempotencyListener`, purge command); `TaskConstraints` holds the write rules; `JsonTextFunction` DQL helper.
- **FR-API-003**: API host hooks (`Api/Hook`): deny-by-default attachments, plan gate, comment edit policy and other host decisions, each with a safe default implementation.
- **FR-SYNC-010**: Provider sync (1.23+): `TaskBoardConnection` links a board to one remote list; `Sync/Provider` adapters (ClickUp, in-memory test provider) read through `ProviderHttpClient` (fixed base URI, timeouts, retries via `SleeperInterface`); `Sync/Entity`, `Sync/Repository`, `Sync/Dto`, `Sync/Event`, `Sync/Exception` (fixed English messages, no provider payload) and `TaskBoardSyncCommand` run resumable slices.
- **FR-SYNC-011**: Sync host hooks (`Sync/Hook`) and people directory (`Sync/People`, `Sync/View`): person resolution, attribution-only mode, mapping decisions, legacy-author migration and view models of remote people.
- **FR-SYNC-012**: Sync content safety: `RemoteContentSanitizer` stores provider text as safe Markdown; `Sync/File` downloads attachments only from allow-listed hosts with size / type checks and SSRF guards.
- **FR-SYNC-013**: Sync status, checklists and tracked time: `Sync/Status` maps remote statuses to columns and cleans unmatched columns after the first import; `SyncChecklistWriter` keeps ClickUp checklists as subtasks; `Sync/Time` hands read-only time entries to the host by window.
- **FR-PORT-001**: Framework-free host ports (REQ-ARCH-001, 2.15+): `Port/*` gates call the host twins (`*Interface` VO-typed successors under `Assignee`, `Comment`, `History`, `Import`, `Move`, `Tenancy`, `View`, `User`), wired by `TaskBoardPortsPass`; `TaskBoardUsers` / `TaskBoardUserDirectoryInterface` / `TaskBoardUserRefType` reference host users by id when `database.user_reference: id`.
- **FR-ORM-010**: Persistence options: XML mapping alternative for every entity (`Resources/config/doctrine-xml`), optional private entity manager (`PrivateEntityManagerPass`, `PrivateEntityManagerReferencesPass`), `Doctrine/*` helpers (key collation for REQ-SEC-006, lock-conflict detection, manager lookup), repositories that see archived tasks and slug uniqueness checks.
- **FR-EVT-010**: Domain events with ids only (`Event/*`, 2.10+): task lifecycle, column and dependency events dispatched after commit so hosts can react without entities.
- **FR-NUM-001**: Task numbers (spec 214, `task_numbers.enabled`): `TaskNumberCounter` / `TaskNumberEntry` ledger per scope, listener numbering on persist, aliases resolved by `TaskNumberLedger`, backfill service and command.
- **FR-CAL-001**: Calendar (spec 214, 2.1+ / 2.14): calendar modes, week chips and host day annotations (`Calendar/*`, `CalendarMode`).
- **FR-REC-001**: Recurring tasks (spec 215, `recurrence.enabled`): `TaskRecurrence` / `TaskOccurrence`, a pure date planner, the series manager and the board-move listener.
- **FR-MOVE-001**: Move and duplicate (specs 215/217): `TaskCopier` with `TaskCopyOptions`, column rules, target boards (`Move/*`), `TaskCopyController`.
- **FR-MULTI-001**: Multi-board list and export (2.13, `multi_board.enabled`): bounded per-board queries, read checks, CSV / streamed XLSX export (`MultiBoard/*`, `TaskBoardMultiBoardController`).
- **FR-FIELD-001**: Native custom fields (2.19, FR-028): `TaskCustomField` / `TaskCustomFieldValue`, filter conditions with operators, form rows and list columns (`Field/*`, `CustomFieldOperator`).
- **FR-FOLDER-001**: Board folders and shared status sets (2.19, FR-031/FR-034): `TaskBoardFolder`, `Folder/*`, `Configuration/*` column specs applied by `BoardColumnsApplier` without stranding tasks, `ColumnType`, restructure policy.
- **FR-TPL-002**: Task templates (2.16, FR-061, `task_templates.enabled`): `TaskTemplate` entity, manager, data and permission port (`Template/*`).
- **FR-TRANS-001**: Column transition policies (2.16, FR-014/015): `ColumnTransitionPolicyInterface` with allow-all default and opt-in humans-only policy; refusals carry codes (`Transition/*`, `ValueObject/ColumnTransition*`).
- **FR-SEARCH-001**: Task text search (2.16, FR-026): `TaskTextSearchInterface`, Doctrine default search and optional FULLTEXT metadata (`Search/*`).
- **FR-TREE-002**: Task tree (2.20, T004): reparent port and domain service with bounded walks and `max_subtask_depth` (`Tree/*`, `DoctrineOrmTaskTreeStore`).
- **FR-MEMBER-001**: Members, avatars and provenance (1.23-2.18): avatar URL policy and resolver port (`Avatar/*`), member badges (`Member/*`), write provenance channel (`Provenance/*`).
- **FR-BOARD-010**: Board creation scope and icons (spec 211, 2.9): scope steps and fields from the host (`Board/*`), board / column icons and default icon filler (`Support/*`, `DefaultColumnIconFiller`, apply-icons command).
- **FR-SVC-010**: Supporting services and DTOs of 1.23-2.20: column manager port, period lock defaults, eraser with signed cursor (`Dto/Erase*`, `EraseCursorSigner`), TimeTrack bridges (`Bridge/TimeTrack`), import helpers (`Import/*`), exceptions, view resolvers and UiKit page plumbing (`TaskBoardPages`).
- **FR-WORKER-002**: Request boundary for kept-alive kernels (REQ-RUNTIME-002): `TaskBoardRequestBoundarySubscriber` + `UnitOfWorkFrames` empty request memos at every unit-of-work boundary.
- **FR-UI-020**: Manage UI templates of 1.23-2.20 (`Resources/views/manage/*`: board create steps, lists, connection dialogs, calendar, multi-board, templates, custom fields) on the UiKit page contract.
- **FR-ASSET-010**: TypeScript sources and co-located Vitest tests added in 1.23-2.20 (`Resources/assets/src/*`: board create steps, openers, confirm dialogs, anchors and other Stimulus controllers).
- **FR-DI-010**: Optional service files per feature (`services_api.yaml`, `services_multi_board.yaml`, `services_numbers.yaml`, `services_recurrence.yaml`, `services_sync.yaml`, `services_templates.yaml`), loaded only when the feature is enabled.

## Success Criteria

- **SC-001**: **939/939** tracked `src/` files mapped in [`code-inventory.md`](code-inventory.md) (audited 2026-11-16 on 2.20.0; `git ls-files src | wc -l`, 20 of them co-located Vitest files).
- **SC-002**: Config keys match `Configuration.php` and Flex recipe defaults.
- **SC-003**: PHPUnit + Vitest + PHPStan pass in CI.
- **SC-004**: Demo with TimeTrack: board + timer + aggregated time on task.

---

## Explicit non-goals

- Native mobile clients.
- Real-time multi-user cursors on kanban (server push).
- Built-in email notifications (host app responsibility).
- Clearing the Doctrine identity map on every request under FrankenPHP worker mode without `services_resetter` (host responsibility; see `docs/FRANKENPHP-WORKER-AUDIT.md`).

---

## Validation

| Check | Command |
| --- | --- |
| Full QA | `composer qa` |
| Demo + TimeTrack | `make -C TimeTrackBundle/demo up-symfony8` |
| Inventory | `find src -type f ! -name '*.test.ts' \| wc -l` |
