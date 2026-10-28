# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.14.0] - 2026-10-28](#1140---2026-10-28)
- [[1.13.1] - 2026-10-28](#1131---2026-10-28)
- [[1.13.0] - 2026-10-28](#1130---2026-10-28)
- [[1.12.0] - 2026-10-28](#1120---2026-10-28)
- [[1.11.1] - 2026-10-28](#1111---2026-10-28)
- [[1.11.0] - 2026-10-28](#1110---2026-10-28)
- [[1.10.1] - 2026-10-28](#1101---2026-10-28)
- [[1.10.0] - 2026-10-28](#1100---2026-10-28)
- [[1.9.0] - 2026-10-26](#190---2026-10-26)
- [[1.8.4] - 2026-10-25](#184---2026-10-25)
- [[1.8.3] - 2026-10-24](#183---2026-10-24)
- [[1.8.2] - 2026-10-21](#182---2026-10-21)
- [[1.8.1] - 2026-10-18](#181---2026-10-18)
- [[1.8.0] - 2026-10-13](#180---2026-10-13)
- [[1.7.2] - 2026-10-13](#172---2026-10-13)
- [[1.7.1] - 2026-10-13](#171---2026-10-13)
- [[1.7.0] - 2026-10-13](#170---2026-10-13)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.8] - 2026-10-09](#158---2026-10-09)
- [[1.5.7] - 2026-09-27](#157---2026-09-27)
- [[1.5.6] - 2026-09-25](#156---2026-09-25)
- [[1.5.5] - 2026-08-24](#155---2026-08-24)
- [[1.5.4] - 2026-08-19](#154---2026-08-19)
- [[1.5.3] - 2026-08-19](#153---2026-08-19)
- [[1.5.2] - 2026-08-18](#152---2026-08-18)
- [[1.5.1] - 2026-08-07](#151---2026-08-07)
- [[1.5.0] - 2026-08-04](#150---2026-08-04)
- [[1.4.0] - 2026-08-03](#140---2026-08-03)
- [[1.3.1] - 2026-07-30](#131---2026-07-30)
- [[1.3.0] - 2026-07-30](#130---2026-07-30)
- [[1.2.4] - 2026-07-29](#124---2026-07-29)
- [[1.2.3] - 2026-07-16](#123---2026-07-16)
- [[1.2.2] - 2026-07-13](#122---2026-07-13)
- [[1.2.1] - 2026-07-08](#121---2026-07-08)
- [[1.2.0] - 2026-07-07](#120---2026-07-07)
- [[1.1.1] - 2026-07-07](#111---2026-07-07)
- [[1.1.0] - 2026-07-06](#110---2026-07-06)
- [[1.0.1] - 2026-07-06](#101---2026-07-06)
- [[1.0.0] - 2026-07-06](#100---2026-07-06)

## [Unreleased]

## [1.14.0] - 2026-10-28

Task colour and the last Bootstrap modals as native dialogs. **Schema change**: one nullable column (see UPGRADING).

### Added

- **Task colour**: `Task::$color` (`#rrggbb` or null, column `task_board_tasks.color`), chosen in the task detail
  ("Colour" row) and in the *Add task* dialog with the column dialogs' palette, a custom colour and *No colour*. The
  Gantt bar of a coloured task takes its colour (border and a tinted background, `--nowo-task-bar-color`); without one
  the priority colours stay. Board cards show it on their left edge (`nowo-task-card--colored`). A change is recorded
  in the activity (`TaskChangeType::Color`, shown as swatches) and listed as `color` in `TaskUpdatedEvent`.
- `TaskFormData::$color`, `TaskFormType` fields `color` (hidden, `#rrggbb` only) and `customColor` (unmapped
  `ColorType`) on the full form; `TaskGanttItem::$color`.
- `_colour_picker.html.twig`: the palette partial shared by the column dialogs and the task colour; the
  `nowo-task-board-picker` controller gains an optional `custom` target (a colour input beside a hidden field).

### Changed

- **Add task**, **Manage people**, **Change priority** and **Add / edit link** are native `<dialog>` elements styled by
  `task-board.css` (top layer, Escape and the focus trap from the browser, the `--nowo-task-overlay` backdrop), without
  Bootstrap layout utilities. Their Stimulus controllers share one helper (`modal-toggle.ts`) and keep the legacy
  `.modal` branch for template overrides.
- **Manage people**: the members as a list (avatar initial, name, role, remove button on one line) and the add form
  as one row (person, role, *Add member*); one column on phones.
- **Change priority**: the picked option is highlighted at once.

### Fixed

- The add task dialog's Escape handler was not bound (a method instead of an arrow function) and could not be removed.
- Activity avatars showed a blank initial (the member label's leading whitespace).

## [1.13.1] - 2026-10-28

### Changed

- **List filters** read as one filter bar in a panel: the fields show placeholders ("Search tasks…", "Any priority",
  "Any column") and keep their labels for screen readers; the priorities are translated.
- The native dialogs' backdrop reads `--nowo-task-overlay` / `--nowo-task-overlay-blur`, so a host gives them the same
  backdrop as its own modals.

### Fixed

- The list filter form no longer uses an inline `onsubmit` handler (blocked by a strict CSP): the list filter
  controller stops the submit.

## [1.13.0] - 2026-10-28

Column editor, dialogs and Gantt on the host-neutral look of 1.12. No schema change.

### Added

- **Column editor** (add / edit column): a colour palette with a custom colour and the icons as a grid
  (`_column_fields.html.twig`, `nowo-task-board-picker` controller over the real form fields).
- **List groups fold** with UiKit's `collapse-panel` peer and the `_disclosure_icon` chevron (state per board and
  column in localStorage); without the peer registered they stay open.
- **Gantt**: at least three weeks drawn (`TaskGanttBuilder::MIN_RANGE_DAYS`), localised weekday initials and month
  names, one-line titles with the status, the task title beside its bar, a toolbar with the legend, and the chart opens
  scrolled to today.

### Changed

- The add / edit column dialogs are native `<dialog>` elements styled by `task-board.css` (top layer, Escape and the
  focus trap from the browser); the column-set dialogs get the same styles, so a host CSS reset no longer misplaces
  them. The legacy `.modal` markup still works with the controllers.
- Board toolbar in one quiet row (hint and column-set buttons), card titles at reading size (three lines at most), a
  neutral status pill for columns without a colour.

## [1.12.0] - 2026-10-28

ClickUp-like views on a host-neutral look (see [USAGE](USAGE.md#look-and-feel-clickup-like-views-theming-and-icons-since-112)).
No schema change.

### Added

- **Design tokens** `--nowo-task-*` (surface, border, ink, muted, accent, radius, success / danger / warning / info /
  purple and their soft variants): every colour of `task-board.css` reads them first, then the Tabler variables, so a
  host themes the board, list, Gantt, task page and panel without overriding rules.
- **Inline "+ New task"** at the foot of every board column and list group (`inlineTaskForms`, `task_create?inline=1`
  with the compact form, back to `_target_path`).
- **Task detail**: status pill in the column's colour, title edited in place, one attribute grid (people, due date,
  time, tags), subtasks as a checklist with an inline *Add subtask*, sections for links, dependencies, documentation
  and the activity stream (now last).
- **Board cards**: priority flag (not repeated for *normal*), due date (late dates highlighted), assignee avatars.
- **List**: grouped by column (colour, icon, count, collapsible), people and priority columns.
- Member names prefer `displayName` / `fullName` over the e-mail.

### Changed

- Icons go through the UiKit icon set (`components/_icon.html.twig`): no Tabler webfont is required; `ux_icon` hosts
  import the icons listed in USAGE.
- The detail, card, list, links, subtasks and activity templates lay out with the bundle's own classes instead of CSS
  framework utilities (`d-flex`, `row` / `col`, spacing, `card`, `list-group`); hidden parts use the `hidden`
  attribute instead of `d-none`.

### Fixed

- The task page under a strict CSP: the link removal confirmation and the external-link button no longer use inline
  event handlers (and no link sits inside a button any more).
- Hosts without Bootstrap utilities no longer see the hidden column / priority selects and empty icon boxes.

## [1.11.1] - 2026-10-28

### Fixed

- **A board without columns** (all its columns deleted, or created empty by a host): the board page and the task page
  answered 500 — the templates rendered the task form's column field, which `TaskFormType` only adds when the board has
  columns.

## [1.11.0] - 2026-10-28

Columns, column sets and a task panel for side drawers (spec 171 block E of Symfony Beacon). All opt-in or backward
compatible. **Schema change:** a nullable column on the board columns and a new table `task_board_column_sets`. See
[UPGRADING](UPGRADING.md#to-1110).

### Added

- **Column icon:** `BoardColumn::$icon` (`getIcon()` / `setIcon()`, e.g. `tabler:bug`), `BoardColumnFormData::$icon`,
  `BoardColumnFormType` option `icon_choices`, config `board.column_icons` (30 curated Tabler icons by default,
  `Configuration::DEFAULT_COLUMN_ICONS`; `[]` hides the field) and `board.default_columns[].icon`. The column header
  renders it through `manage/_column_icon.html.twig` → UiKit `components/_icon` (`ux_icon()` with `icon_set: ux_icon`).
- **Delete a column:** route `column_delete` (POST, CSRF `task_board_column_delete`), `BoardColumnManager::delete()`
  (tasks move to the end of the first remaining column, completed / reopened as that column is or is not the last
  one; the last column of a board is kept), `TaskBoard::removeColumn()`, a delete button in the edit column dialog.
- **Column sets:** entity `ColumnSet` (name, `createdBy`, `createdAt`, `scopeKey`, ordered `items` `{name, color,
  icon}`; `ColumnSet::fromBoard()`), `ColumnSetRepositoryInterface` / `DoctrineOrmColumnSetRepository`,
  `ColumnSetManager` (`available()`, `find()`, `saveFromBoard()`, `applyToBoard()`, `delete()`), routes
  `column_set_create`, `column_set_apply`, `column_set_delete`, forms `ColumnSetFormType` / `ColumnSetApplyFormType`,
  the board dialogs **Save columns as set** / **Add columns from set** (`_column_sets_dialogs.html.twig`) and the
  optional set of a new board (`TaskBoardFormData::$columnSetId`, `TaskBoardFormType` option `column_set_choices`,
  `TaskBoardCreator::create(..., ?ColumnSet $columnSet)`).
- **Hooks:** `TaskBoardEvents::COLUMN_SET_QUERY` (`ColumnSetQueryEvent`: scope of the listed and saved sets, default
  the board's tenant key; `setScopeKey()`, `overrideList()`), `TaskBoardEvents::COLUMN_SET_ACCESS_CHECK`
  (`ColumnSetAccessCheckEvent`: who may delete a set, default its creator or a `security.manage_roles` role) and
  `TaskBoardResourceAccessChecker::canDeleteColumnSet()`; `BoardListQueryEvent::getTenantKey()` (the tenant of the
  index being listed).
- **Task panel:** route `task_panel` (GET `…/task/{taskId}/panel`) and template `templates.task_panel`
  (`manage/task_panel.html.twig`, no layout): the task page's blocks for side drawers. Its forms post to the usual
  routes with `_target_path` and come back to it (local paths only); a write sent with fetch (`X-Requested-With:
  XMLHttpRequest` or `Turbo-Frame`) answers the refreshed panel (422 for an invalid edit), and so does the task page.
  Board cards, list rows and Gantt bars carry `data-task-panel-url`.
- `nowo-task-board-dialog` Stimulus controller (native `<dialog>`), `data-nowo-task-vars` (CSS custom properties set
  by `task-board.js`), translations (7 locales) for the new controls and flashes.

### Changed

- **No inline `style=""` in the templates** (column colour, column dots, Gantt grid / bars / depth, subtask progress):
  CSS custom properties from `data-nowo-task-vars` and a native `<progress>`; more semantic `nowo-task-*` classes,
  empty states (column without tasks, no column sets), `scope="col"` on the list headers, labelled column sections.
- `manage/task.html.twig` takes its blocks from `manage/_task_blocks.html.twig` (`{% use %}`), shared with the panel;
  the edit form posts to an explicit `task` route URL. The task write actions return `Response` (a redirect, or the
  panel for a fetch) instead of `RedirectResponse`.
- The add / edit column and add task dialogs are only rendered for board editors.
- `TaskBoardManageController` takes the optional trailing arguments `$columnSetManager` and `$columnIcons`;
  `TaskBoardResourceAccessChecker::filterBoards()` takes an optional tenant key.

## [1.10.1] - 2026-10-28

### Fixed

- **Task page with an assignee provider** (`assignee_provider`): it answered 500 — `TaskMemberFormType` set the allowed
  types of `user_choices` before declaring the option ("The option user_choices does not exist").
- **Add a subtask** (`POST …/task/{taskId}/subtask`): it answered 500 — the handler rebuilt the full task form while the
  page posts the compact one (title and the hidden column), so the priority was missing.

## [1.10.0] - 2026-10-28

Host integration for multi-tenant apps (all opt-in; without the new options the bundle behaves as in 1.9). **Schema
change:** two nullable columns on the boards and two on the task links. See [UPGRADING](UPGRADING.md#to-1100).

### Added

- **Boards per tenant (`tenancy`):** `TaskBoard::$tenantKey` (nullable, indexed), `TaskBoardRepositoryInterface::findActiveByTenant()` / `findOneByTenant()`, `tenancy.enabled`, `tenancy.tenant_paths` (`path`, `name_prefix`, `slug_prefix`, `slug_requirement`: every manage route copied as `<name_prefix><route>` under the host's own URL, e.g. `/projects/{tenantSlug}/tasks`), `tenancy.single_board` (the tenant index opens its board, created on the first visit) and `tenancy.resolver` (`TaskBoardTenantResolverInterface` → `TaskBoardTenant`; default: the slug is the tenant). `TenantPathUrlGenerator` decorates the router (only with tenancy on) so `nowo_task_board_*` links go to the tenant copy and reuse the `{tenantSlug}` of the current tenant page; `TenantPaths::bundleRoute()` maps a copy back. A board or task of another tenant answers 404.
- `board.default_columns` (columns of a new board) and `board.top_level_only` (Kanban shows top-level tasks with a subtask counter).
- **Host object links:** `TaskLinkType::HostObject`, `TaskLink::$objectType` / `$objectId`, `TaskLink::forHostObject()`, `TaskLinkAttacher::attachObject()` / `detachObject()`, `TaskRepositoryInterface::findByHostObject()`, and `TaskObjectLinkResolverInterface` (`supports`, `label`, `url`, `badge` → `TaskObjectBadge`; autoconfigured, tag `nowo_task_board.object_link_resolver`) rendered on the task page and through the Twig functions `nowo_task_board_link_label()` / `_url()` / `_badge()`.
- **Assignee provider:** `assignee_provider` (`TaskAssigneeProviderInterface::assignableUsers()` / `canBeAssigned()`) limits the member picker (`TaskMemberFormType` option `user_choices`) and `TaskMemberAssigner::assign()` (`UnassignableUserException`, flash `task_board.flash.member_not_assignable`).
- **Events after writes** (`TaskBoardEvents::TASK_CREATED`, `TASK_UPDATED` with the changed field names, `TASK_MOVED` with the from / to column, `TASK_COMPLETED`, `TASK_REOPENED`, `TASK_MEMBER_ADDED`), dispatched by `TaskManager` and `TaskMemberAssigner` after the flush.
- **Templates:** blocks per region (`nowo_task_board_toolbar`, `nowo_task_board_columns`, `nowo_task_board_column`, `nowo_task_board_list`, `nowo_task_panel`, `nowo_task_toolbar`, `nowo_task_subtasks`, `nowo_task_relations`), partials `_board_column`, `_task_card` (subtask done / total) and `_subtask_tree` (recursive, any depth, done / total per node, `<details>` expand / collapse), semantic `nowo-task-*` classes; `TaskTree` (subtree and progress from the board's tasks in one query), `SubtaskProgress` and the Twig function `nowo_task_board_subtask_progress()`.
- Translations (7 locales): `task_board.link.host_object`, `task_board.flash.member_not_assignable`, `task_board.board.subtasks_count`.

### Changed

- The task page counts subtasks at any depth (done / total of the whole subtree) and renders the nested levels through `_subtask_tree.html.twig`; the task, board and list pages receive `taskTree`.
- The link form no longer offers a link type for host objects (`TaskLinkType::userChoices()`).
- `TaskManager`, `TaskMemberAssigner`, `TaskBoardCreator`, `TaskBoardTwigExtension` and `TaskBoardManageController` take new optional trailing constructor arguments (event dispatcher, assignee provider, default columns, link resolver, tenant context, `top_level_only`).

### Security

- **Board writes need edit access, not view access.** Adding, renaming or reordering columns, creating tasks and importing (`createColumn`, `updateColumn`, `reorderColumns`, `createTask`, `importBoard`) used to check only `canViewBoard()`, so a user granted read access through `BOARD_ACCESS_CHECK` could still change the board. They now require the new `TaskBoardResourceAccessChecker::canEditBoard()` (view access that no listener marked read-only) and answer 403 otherwise. A listener marks read-only access with the new `BoardAccessCheckEvent::markReadOnly()` / `isReadOnly()`. The tasks of a read-only board are read-only too (`canEditTask()`), participants included, unless a `TASK_ACCESS_CHECK` listener grants the task explicitly. The board and task pages receive `canEditBoard` / `canEditTask` and hide the write controls (import, add / edit column, add task, save, add subtask) for read-only users.
- **No `|raw` in the page header.** `manage/_page_header.html.twig` printed `headerActions` with `|raw` (an HTML string built from `path()` and translations). `headerActions` is now a list of `{href, label, icon, class}` rendered with auto-escaping; a string from an older override is printed escaped.

## [1.9.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-190).

### Added

- `make check-no-raw-html-form` (`.scripts/check-no-raw-html-form.sh`) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Manage UI (REQ-TWIG-005): the list filters (`TaskListFilterType`, unmapped, client-side), the task advance form, link remove form, member remove buttons (`CsrfActionType`) and the priority modal (`TaskPriorityFormType`) are Symfony Forms rendered with `form_start` / child loop / `form_end` instead of raw `<form>` / `<select>` / hand-rolled `_token` inputs. The controller validates them with `handleRequest` + `isSubmitted()` / `isValid()` and always passes the new form variables. Same routes, field names and CSRF ids; see [UPGRADING](UPGRADING.md#unreleased).

## [1.8.4] - 2026-10-25

Reproducible JS bundle: the build stamp is a hash of the sources instead of the build time. See [UPGRADING](UPGRADING.md#to-184).

### Changed

- **Assets:** reproducible bundle build. `__TASK_BOARD_BUILD_TIME__` now holds a build id (a hash of the TypeScript/CSS sources and `pnpm-lock.yaml`) instead of the build time, so rebuilding unchanged sources (`make assets`, `make release-check`) no longer rewrites the committed JS (`<html data-task-board-build>` carries the id).
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain with `ensure-up` first, then the Cursor co-author, open-PR and Twig `extra` gates, composer-sync, the new `cs-fix` step, cs-check, rector-dry, phpstan, igor, translations, the 100% coverage gate (`test-coverage-100` instead of plain `test`), the demo release-check and test-ts. `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.
- Internal: applied the pending Rector suggestions in `TaskBoardResourceAccessChecker` (private static helpers made non-static, nullable checks as `instanceof`) and its unit test; no behaviour change. `make rector-dry` is clean again.
- Developer tooling: the PHP container now ships Node.js and pnpm 10, and `make test-ts` runs `pnpm install --frozen-lockfile` + `pnpm run test:coverage` and fails the build on errors (it used to print a hint and succeed when pnpm was missing, so `release-check` never ran the TypeScript tests).

## [1.8.3] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-183).

### Changed

- Views: the board create (`manage/index.html.twig`) and import (`manage/import.html.twig`) forms render their body
  through the child loop (`{% for child in form %}` + `not child.rendered` + `form_row(child)`), per REQ-TWIG-003.
  The forms with an intentional custom layout (task detail and subtask forms in `manage/task.html.twig`, the add
  task / add column / edit column / task links / task members modals) keep their explicit children (grid columns,
  Stimulus targets; rule 3) and now end with the same loop, so fields added to the FormType are rendered
  automatically.

## [1.8.2] - 2026-10-21

Dependency refresh (bundle and demos), php-cs-fixer ParallelConfig(2). See [UPGRADING](UPGRADING.md#to-182).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19); shipped assets rebuilt.
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order, canonical issue templates, CONTRIBUTING Cursor Agent settings (REQ-IDE-006), tables of contents, committed demo `.env.test` (REQ-DEMO-003), Spec Kit inventory refresh (155/155, FR-SEC-005).

## [1.8.1] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-181).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); dev and demo installs now resolve UiKit 2.2.0 (no committed lock). Templates are unchanged (compatibility release). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules on the board and manage pages; to keep the 1.x cascade, override the page `stylesheets` blocks and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

## [1.8.0] - 2026-10-13

### Fixed

- **Tiptap:** the task form no longer fails with `Unknown Tiptap config profile "task"` when `nowo-tech/tiptap-editor-bundle` is installed but the app did not define that profile. TaskBoardBundle now prepends a `nowo_tiptap_editor.profiles.task` profile (simple variant, toolbar, 220px; reuses the host default profile's `form_theme`) unless the host already defines `task`. Documented in CONFIGURATION.md / INSTALLATION.md; the demo now only configures its default profile.

## [1.7.2] - 2026-10-13

### Fixed

- **Task detail page returned HTTP 500** (since 1.0.0): `manage/task.html.twig` rendered `task.loggedMinutes`, which the `Task` entity did not expose. Added `Task::getLoggedMinutes()`: whole minutes from TimeTrack timers (`total_time_seconds`, 0 without TimeTrackBundle) plus manual `TaskTimeEntry` rows.
- **Task history section failed to compile** (since 1.0.0): `_task_history_section.html.twig` used a non-existent `json_decode` Twig filter for tag changes. It now uses the new `nowo_task_board_tag_list` filter, which decodes the stored JSON list (invalid or empty values give an empty list).
- Test coverage: the index, board, import and task detail manage pages are now rendered with real entities and `strict_variables` so missing entity properties or filters fail in CI.

## [1.7.1] - 2026-10-13

### Changed

- `docs/CONFIGURATION.md`: boards without a team are visible to their creator and `security.manage_roles` only; task members reach their tasks, not the board (the doc listed task members as board viewers).

## [1.7.0] - 2026-10-13

### Fixed

- **Translations:** 48 keys used by the templates/controller were missing from all 7 locales (`task_board.gantt.*`, `task_board.flash.column_updated|link_updated|link_removed|member_removed`, `task_board.task.*_modal_*`, `task_board.history.completed_*`, `task_board.history.type.*`, `task_board.priority.*`, …) and rendered as raw keys; added real EN/ES/DE/FR/IT/NL/PT translations. New `TranslationKeysTest` fails when a literal or enum-driven key is missing from any locale or locales drift apart.
- **Renamed routes:** manage templates hard-coded ~48 `nowo_task_board_*` route names, so changing `routes.<key>.name` broke every page. Templates now resolve names via the new Twig function `nowo_task_board_route('<key>')`, fed from the `routes` config (covered by a rendering test with renamed routes).
- **Add-task modal default column:** the board page pre-selected the first column *name* instead of its id, so the default column never matched.
- **Duplicate column names:** column choices were keyed by name, so columns sharing a name disappeared from the task form; duplicates are now labelled `Name (2)`, `Name (3)`…
- **Drag & drop positions:** the client sends the target column's card count as position, which collided with existing (gapped) positions. `TaskManager::move()` now inserts the task at that index and renumbers the target column `0..n-1`.
- **List and Gantt views** hid completed (last-column) tasks although the Gantt draws completed bars and the list filters by column; both now include them, and Gantt subtasks whose parent is not on the timeline are shown at root level instead of disappearing.
- `services.yaml` no longer registers entities, DTOs, events, enums, exceptions and value objects as autowired services.
- Docs: routes table now lists `task_link_update`, `task_link_remove` and `task_member_remove`; documented that `TaskBoard::team` is assigned programmatically (no UI picker).

### Deprecated

- `nowo_task_board.firewall` was never read by the bundle; setting it now triggers a deprecation. Protect the manage routes with your own `security.yaml` firewall/`access_control`.

### Changed

- Dev tooling: vitest/`@vitest/coverage-v8` `^4.1.11` (from `^3.0.0`), jsdom `^30.1.2`; CI TypeScript job runs on Node 22.
- Test suite now covers importers (ClickUp JSON, Jira CSV, Trello JSON, parser/mapper helpers), the import command and the extension prepend logic; the 100% coverage gate passes again. Dead branches in `DelimitedTableParser::parse()` were removed.

## [1.6.0] - 2026-10-13

### Security

- **IDOR fix:** boards, tasks, columns, links and members are now authorized per resource. Only the board creator, members of the board's team, users attached to a task and holders of `security.manage_roles` (default `ROLE_ADMIN`) can open or modify them (403 otherwise); the board index lists accessible boards only. New options `security.resource_scoping` (default `true`) and `security.manage_roles`.
- Task link URLs must be `http`/`https` (`javascript:`, `data:`… rejected); links are also guarded at render time.
- Column colours must be hex colours (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`); non-hex values stored earlier are not rendered into `style`.

### Fixed

- `TaskFormType` no longer fails with "Could not load type" when the optional `nowo-tech/tiptap-editor-bundle` / `nowo-tech/tag-input-bundle` are not installed: it falls back to a textarea and a comma-separated tags field.
- Moving a task to a column of another board (or an unknown column) now returns 400 instead of silently detaching the task from every column.
- Nested `columnOrder[]` input on column reorder now returns 400 instead of a 500 `TypeError`.

### Changed

- `BOARD_LIST_QUERY`, `BOARD_ACCESS_CHECK` (new `BoardAccessCheckEvent`) and `TASK_ACCESS_CHECK` are now dispatched; `TaskAccessCheckEvent::markReadOnly()` blocks mutations. The other `TaskBoardEvents` constants are documented as reserved (not dispatched).
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

## [1.5.8] - 2026-10-09

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

### Dependencies

- `doctrine/orm` constraint raised to `^3.7` (drops ORM 2.x; required by `SortDirection`).
- Dev: `igor-php/igor-php` `^0.10.0`.
- Demo (`demo/symfony8`): regenerated `config/reference.php` for Symfony 8.1.8.

## [1.5.7] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.5.7]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.7

## [1.5.6] - 2026-09-25

### Fixed

- **FrankenPHP worker mode (no kernel reset):** a failed `flush()` (unique slug, duplicate member, deadlock) no longer leaves the EntityManager closed for the rest of the worker's life: repositories and `TaskImportOrchestrator` reset the closed manager through `ManagerRegistry` before rethrowing (`Doctrine\RecoveringFlusher`).
- `TaskBoardCreator` appends a numeric suffix (`-2`, `-3`, …) when the board slug is already taken instead of hitting the unique index.
- `TaskMemberAssigner::assign()` returns the existing member when the same user already has that role on the task (no duplicate insert).
- `TaskAccessGuard::canTrack()` refreshes the task and its board before deciding, so a stale identity map in a long-running worker cannot grant or deny time tracking on outdated assignee/team data.
- `TimeSpentAggregatorListener` refreshes a managed task before adding timer duration (avoids overwriting concurrent `total_time_seconds` under worker mode).
- `TaskBoardRouteLoader` can be loaded more than once per process (the "routes already loaded" guard broke router cache rebuilds in debug workers).
- Imports compute task positions with one query per import instead of one per row; imported tasks in the same column now get consecutive positions.
- `DelimitedTableParser` no longer uses `strtok()` (global state kept the last upload in memory).

### Added

- **Docs:** [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) (scenario B audit + remediation); host checklist in [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md); README links.

### Notes

- New optional constructor arguments (`?ManagerRegistry`) on the Doctrine repositories that flush, `TaskImportOrchestrator`, `TaskAccessGuard`, and `TimeSpentAggregatorListener`; wired automatically. No configuration changes.
- Under `reset_kernel: false`, hosts should still clear the Doctrine identity map between requests (or keep `services_resetter`) for rendered page freshness and memory — see the audit.

## [1.5.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Dependencies:** routine Composer/npm bumps (Dependabot).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.5.5]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.5

## [1.5.4] - 2026-08-19

### Security

- **Flex recipe:** ship `security_nowo_task_board.yaml` access rules (REQ-SEC-004). Prefer **`^1.5.4`** over `v1.5.3`.

## [1.5.3] - 2026-08-19

### Security

- **Flex recipe:** `security.access_control` for task board routes (REQ-SEC-004) — release notes only; recipe files ship in **1.5.4**.

## [1.5.2] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.5.2]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.2

## [1.5.1] - 2026-08-07

### Fixed

- **CI:** correct indentation in `src/Resources/config/services_timetrack.yaml` (YAML parse error broke container boot / PHPUnit).

[1.5.1]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `task_board` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires `nowo-tech/ui-kit-bundle` `^1.4`. Layout/manage Twig load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import `@NowoUiKitBundle/macros/ui.html.twig` (domain macros `_task_member_label` / `_task_priority_tag` unchanged). Extension seeds `nowo_ui_kit` from `templates.css_framework` when the host has not configured UiKit. Demo registers `NowoUiKitBundle` + `nowo_ui_kit.yaml`.

[1.5.0]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-08-03

### Added

- **REQ-UI-002** — `security.allow_unauthenticated` (default `false`) and `AllowAllTaskBoardAccessChecker` for trusted demos/dev only.
- Compile-time guard: when `allow_unauthenticated` is `false`, `symfony/security-bundle` is required (`LogicException` otherwise).
- GitHub hygiene workflows: Dependabot, stale issues/PRs (`actions/stale` v11), semantic PR title lint, Copilot instructions.

### Changed

- CI / local Compose no longer clone or path-mount sibling TimeTrack / TagInput / TipTap repos; TimeTrack comes from Packagist (`^1.0`).
- Demo `composer.json` pins `nowo-tech/twig-inspector-bundle` to `^1.0`.
- Docs: CONFIGURATION / SECURITY document `allow_unauthenticated` and the SecurityBundle requirement.

### Compatibility

- PHP `>=8.2`, `<8.6`; Symfony `^7.4 || ^8.0`.
- Manage UI with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.3.1] - 2026-07-30

### Security

- **REQ-SEC-005** — CSRF on bare manage POSTs that previously lacked tokens: column reorder, task move (kanban drag), task advance, priority update, remove link, remove member. Invalid/missing `_token` → access denied (fail-closed). Bundle Twig and board Stimulus `fetch` now send matching tokens.

## [1.3.0] - 2026-07-30

### Added

- **REQ-UI-001 layout wiring** — `templates.layout` is exposed as Twig global `nowo_task_board_layout` via `TaskBoardTwigExtension`. Manage pages `{% extends nowo_task_board_layout %}` and stack CSS/JS with `{{ parent() }}`. Demo `layout.html.twig` keeps Tabler CDN; hosts set `templates.layout` to the project layout or a bridge (`layout_integrate_host.html.twig`). Documented in CONFIGURATION.md / USAGE.md / UPGRADING.md.
- **REQ-UI-001 `templates.css_framework`** — enum (`tabler` default, matching demo CDN; also `bootstrap5` / `bootstrap` / `bootstrap4` / `tailwind` / `foundation` / `custom` / `none`). Container parameter `nowo_task_board.templates.css_framework` and Twig global `nowo_task_board_css_framework`. Invalid values rejected.

## [1.2.4] - 2026-07-29

### Added

- FrankenPHP-friendly banner + `docs/DEMO-FRANKENPHP.md`; `make demo-smoke` / `verify` + `.github/workflows/demo-smoke.yml` (REQ-DEMO-008/002, DOCS-017, TEST-011).
- `make validate-translations`, `make check-open-prs`, `make down-dev` (REQ-MAKE-004 / REL-003 / MAKE-007).
- PHPStan stubs for optional TipTap / TagInput form types; empty `ignoreErrors: []` (REQ-CS-006).
- **REQ-CS-005:** `nowo-tech/phpstan-frankenphp` in `require-dev` with classic + worker rulesets.
- GitHub About Website + Topics (REQ-DOCS-018).

### Changed

- `demo/symfony8` image: `dunglas/frankenphp:1-php8.5-bookworm`; `FRANKENPHP_MODE=worker` (REQ-DEMO-010).
- PHPUnit / CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- Composer keywords: `php`, `frankenphp` (REQ-PKG-004).
- README Documentation order; TOC on long docs (REQ-DOCS-002 / DOCS-005).
- Entities: drop unused `repositoryClass`; host user via `\App\Entity\User::class`; `getCreatedAt()` where needed.
- Demo `access_control` path aligned to `/tools/task-board`.
- Removed `phpstan-baseline.neon` (findings fixed or stubbed).

### Fixed

- TaskGanttBuilder null-safe date range for PHPStan level 8.
- Layout: do not link TimeTrack route when TimeTrack is not installed (demo smoke HTTP 500).
- Demo: register DataFixtures services; recreate schema on `make up`.

## [1.2.3] - 2026-07-16

### Added

- **REQ-GIT-001** — reject Cursor `Co-authored-by` trailers: `.scripts/check-no-cursor-coauthor.sh`, `.githooks/commit-msg`, CI job `git-hygiene`, and `make setup-hooks` / `make check-no-cursor-coauthor` / `make strip-cursor-coauthor-from-history`.
- **Code of Conduct** — [Contributor Covenant](../CODE_OF_CONDUCT.md); linked from README and CONTRIBUTING.
- **CI docs** — [GITHUB_CI.md](GITHUB_CI.md) documents the no-Cursor-co-author audit for nowo-tech bundles.
- **Translations** — import UI strings for DE, ES, FR, IT, NL, and PT (previously only EN/ES had full import coverage in some locales).

### Fixed

- **English translations** — replace German leftovers in `NowoTaskBoardBundle.en.yaml` (back to board, dates, description, assignees, subtasks, edit task).
- **Locale placeholders** — translate `task_board.board.task_placeholder` (and ES `task.edit`) instead of leaving English/German stubs.

### Changed

- **Release process** — `make release-check` includes `check-no-cursor-coauthor`; RELEASE.md reminds to re-check after the release commit (REQ-GIT-001).

## [1.2.2] - 2026-07-13

### Changed

- **Development** — `make test-coverage` writes `coverage-php.txt` and prints the PHP Lines % via `.scripts/php-coverage-percent.sh` (REQ-TEST-008 contract).
- **Docker** — named volume `coverage-data` for `/app/coverage` in `docker-compose.yml` (avoids host permission issues).
- **`.gitignore`** — ignore `.cursor/sandbox.json` and `coverage-php.txt`.

### Added

- **`.cursorignore`** — excludes vendor, caches, build outputs, and heavy binaries from Cursor indexing.
- **PHP CS Fixer** — exclude auto-generated `demo/symfony8/config/reference.php` from the finder.

## [1.2.1] - 2026-07-08

### Added

- **Import export guides** — English manuals under `docs/import/` for exporting boards from [ClickUp](import/clickup.md), [Jira](import/jira.md), and [Trello](import/trello.md) (field mapping, CLI examples, and import tips).
- **GitHub Spec Kit** — `.specify/` workflows, templates, and [SPEC-KIT.md](SPEC-KIT.md) for spec-driven development in this repository (maintainer tooling; no runtime change for bundle consumers).

### Changed

- **Documentation** — README, USAGE, CONFIGURATION, and SPEC-DRIVEN-DEVELOPMENT link to the new import guides and Spec Kit docs.

## [1.2.0] - 2026-07-07

### Added

- **Task import** — upload CSV/JSON exports from **ClickUp**, **Jira**, and **Trello** into an existing board (`board_import` route, manage UI, and `nowo:task-board:import` console command).
- **Extensible importers** — `TaskImporterInterface` with tagged services (`nowo_task_board.task_importer`) for adding more sources.
- **ClickUp** — CSV and JSON importers; maps status, priority, description, assignee, due date, time estimate, tags, parent ID, and task URL; auto-creates missing status columns.
- **Jira CSV** — maps Summary, Status, Priority, Description, Assignee, Due Date, and Issue key.
- **Trello JSON** — maps board export cards (name, list/status, description, due date, labels, short URL).
- **Idempotent re-import** — skips tasks whose external ID was already imported (stored on `TaskLink.externalId`).
- **Assignee mapping** — optional `TaskImportUserResolverInterface` service alias to resolve import emails to application users.
- **Translations** — import UI strings in English and Spanish.

## [1.1.1] - 2026-07-07

### Fixed

- **Doctrine repository stubs** — inject `EntityManagerInterface` in `DoctrineOrmTaskDependencyRepository`, `DoctrineOrmTaskDocumentRepository`, `DoctrineOrmTaskMemberRepository`, and `DoctrineOrmTaskTimeEntryRepository` so Symfony can wire services registered by `TaskBoardExtension` (fixes DI errors when running demos with path-mounted bundles).
- **Asset package path** — correct `nowo_task_board` base path to `/bundles/taskboard` (Symfony `assets:install` output for `TaskBoardBundle`).
- **Form translations** — set `translation_domain` to `TaskBoardBundle::TRANSLATION_DOMAIN` on all manage form types so labels resolve from `NowoTaskBoardBundle` translations.

## [1.1.0] - 2026-07-06

### Changed

- **TimeTrack is optional** — `nowo-tech/time-track-bundle` is no longer a runtime dependency. Bridge services (`TaskBoardTaskProvider`, `TaskBoardTeamContextProvider`) and `TimeSpentAggregatorListener` load only when TimeTrack is installed (`interface_exists` check). Install TimeTrack explicitly and wire `nowo_task_board.task_provider` / `nowo_task_board.team_context_provider` for timer integration.
- **`composer.json`** — TimeTrack moved to `require-dev`; added `suggest` entry; removed local path repository from the published manifest.
- **CI** — TimeTrack installed via `require-dev` in `.github/ci/composer-install*.sh` (path repo clone unchanged).
- **Documentation** — README, INSTALLATION, CONFIGURATION, USAGE, SECURITY, UPGRADING, and Flex `post-install.txt` describe TimeTrack as optional.
- **Development** — Makefile generates gitignored `composer.dev.json` when `../TimeTrackBundle` is present (path repo for local integration tests).

## [1.0.1] - 2026-07-06

### Fixed

- **`DoctrineOrmTaskLinkRepository`** — inject `EntityManagerInterface` so Symfony can autowire the repository.
- **CI** — install dependencies without `composer.lock` via `.github/ci/composer-install.sh` (jq + full `composer update`).
- **CI** — clone TimeTrackBundle by tag `v1.0.0` (matches `^1.0` constraint).
- **CI** — stop PHP CS Fixer from reformatting `composer.json` (exclude from finder; code-style job commits `src/` and `tests/` only).

### Changed

- **CI matrix** — Symfony **7.4**, **8.0**, and **8.1** (PHP 8.2–8.5; Symfony 8 requires PHP ≥ 8.4).

## [1.0.0] - 2026-07-06

First stable release of **TaskBoard Bundle**.

### Added

- **Task boards** — boards with configurable columns, slug, description, optional team, and archive support.
- **Tasks** — kanban columns, priorities, statuses, subtasks, assignees, due dates, and change history.
- **Teams** — teams and team members with manager/member roles.
- **Task links** — external links (including GitLab merge request URL parsing).
- **Task dependencies** — blocking relationships for Gantt view.
- **Web UI** — manage routes at `/tools/task-board` (index, kanban board, list, Gantt, task detail).
- **TimeTrack integration** — `TaskBoardTaskProvider` and `TaskBoardTeamContextProvider` bridges; `TimeSpentAggregatorListener` updates `task.total_time_seconds` on timer stop.
- **Access control** — `TaskBoardAccessCheckerInterface`, `TaskAccessGuard`, and extensibility events (`BoardListQueryEvent`, `TaskAccessCheckEvent`, etc.).
- **Configuration** — `user_class`, `table_prefix`, routes, templates, security roles, and optional `team_membership_resolver`.
- **Persistence** — Doctrine ORM entities and repositories (`task_board_*` tables).
- **TypeScript / Stimulus** — Vite + pnpm assets (`task_board.js`, package `nowo_task_board`).
- **Translations** — `NowoTaskBoardBundle` domain (EN, ES, DE, FR, IT, NL, PT).
- **Symfony Flex recipe** — `1.0.0` with default config and routes.
- **Demo** — Symfony 8.1 + FrankenPHP + MySQL (`demo/symfony8/`).
- **Tooling** — PHPUnit, Vitest, PHP-CS-Fixer, Rector, PHPStan, GitHub Actions CI.

### Requirements

- PHP >= 8.2, < 8.6
- Symfony ^7.4 || ^8.0
- Doctrine ORM ^2.15 || ^3.0
- `nowo-tech/time-track-bundle` ^1.0 (required in 1.0.x; optional from 1.1.0)

[Unreleased]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.9.0...HEAD
[1.9.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.8.4...v1.9.0
[1.8.4]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.8.3...v1.8.4
[1.8.3]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.8.2...v1.8.3
[1.8.2]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.8.1...v1.8.2
[1.8.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.7.2...v1.8.0
[1.7.2]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.7.1...v1.7.2
[1.7.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.5.8...v1.6.0
[1.5.8]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.5.7...v1.5.8
[1.5.6]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.6
[1.5.5]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.5
[1.4.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.4...v1.3.0
[1.2.4]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.3...v1.2.4
[1.2.3]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.0.0
