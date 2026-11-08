# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[2.2.0] - 2026-11-08](#220---2026-11-08)
- [[2.1.1] - 2026-11-07](#211---2026-11-07)
- [[2.1.0] - 2026-11-07](#210---2026-11-07)
- [[2.0.2] - 2026-11-07](#202---2026-11-07)
- [[2.0.1] - 2026-11-07](#201---2026-11-07)
- [[2.0.0] - 2026-11-07](#200---2026-11-07)
- [[1.30.0] - 2026-11-07](#1300---2026-11-07)
- [[1.29.0] - 2026-11-07](#1290---2026-11-07)
- [[1.28.0] - 2026-11-06](#1280---2026-11-06)
- [[1.27.0] - 2026-11-06](#1270---2026-11-06)
- [[1.26.3] - 2026-11-06](#1263---2026-11-06)
- [[1.26.2] - 2026-11-06](#1262---2026-11-06)
- [[1.26.1] - 2026-11-06](#1261---2026-11-06)
- [[1.26.0] - 2026-11-06](#1260---2026-11-06)
- [[1.25.2] - 2026-11-05](#1252---2026-11-05)
- [[1.25.1] - 2026-11-05](#1251---2026-11-05)
- [[1.25.0] - 2026-11-05](#1250---2026-11-05)
- [[1.24.0] - 2026-11-05](#1240---2026-11-05)
- [[1.23.2] - 2026-11-05](#1232---2026-11-05)
- [[1.23.1] - 2026-11-04](#1231---2026-11-04)
- [[1.23.0] - 2026-11-04](#1230---2026-11-04)
- [[1.22.2] - 2026-11-04](#1222---2026-11-04)
- [[1.22.1] - 2026-11-04](#1221---2026-11-04)
- [[1.22.0] - 2026-11-03](#1220---2026-11-03)
- [[1.21.0] - 2026-11-03](#1210---2026-11-03)
- [[1.20.1] - 2026-10-30](#1201---2026-10-30)
- [[1.20.0] - 2026-10-30](#1200---2026-10-30)
- [[1.19.1] - 2026-10-30](#1191---2026-10-30)
- [[1.19.0] - 2026-10-30](#1190---2026-10-30)
- [[1.18.1] - 2026-10-30](#1181---2026-10-30)
- [[1.18.0] - 2026-10-30](#1180---2026-10-30)
- [[1.17.1] - 2026-10-30](#1171---2026-10-30)
- [[1.17.0] - 2026-10-30](#1170---2026-10-30)
- [[1.16.1] - 2026-10-29](#1161---2026-10-29)
- [[1.16.0] - 2026-10-29](#1160---2026-10-29)
- [[1.15.0] - 2026-10-29](#1150---2026-10-29)
- [[1.14.1] - 2026-10-29](#1141---2026-10-29)
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

## [2.2.0] - 2026-11-08

Target: 2.2.0, spec 213 (Task API). Audit IA (2026-11-08): Pass-Low at `637494e`. Opt-in: with `api.enabled: false` (the default) no route, service, listener, parameter or table changes, and the UI's output stays as it was. See [API](API.md).

### Added

- **Task API, contract and docs.** `ApiClickUpContractTest` compares every endpoint's answer with the shape of ClickUp's published response examples (`tests/Fixtures/clickup/shapes.json`: names and types, not live recordings) and pins 40 documented differences. `docs/openapi.yaml` (OpenAPI 3.1), kept in step with the routes by `ApiOpenApiTest`. `docs/API.md` rewritten: turning it on, hooks, access, endpoints, idempotency, errors, differences from ClickUp, material for a host's manual.
- **Task API, management (Full).** Create, rename and delete boards (deleting erases the tasks with time kept, in slices answered 202; a provider-linked board is 409 `LIST_002`), and columns as statuses on four paths of this API's own (`/v2/list/{id}/status…`). Needs the board's manage decision, or the create check and the board plan limit.
- **Task API, files and time.** `POST /v2/task/{id}/attachment` through the host's `TaskApiAttachmentsInterface`; `POST /v2/team/{id}/time_entries` through `TaskApiTimeEntriesInterface`, for the token's own person; `DELETE` of a time entry always 403 `TIME_002`. `Api\Hook\ManualTaskApiTimeEntries` as a time store over the bundle's manual entries.
- **Task API, write endpoints.** Tasks (create, update, delete with time kept), comments, tags, dependencies, links and checklists, through the UI's services. Read writes nothing; My tasks works only on its own tasks and their checklists; custom fields answer 409 `FIELD_001`; a task that follows a provider is not deleted (409 `TASK_002`). Writes need an `API_WRITE` listener (503 `AUDIT_001`) and, for creates, a `QUOTA_CHECK` listener (403 `PLAN_002`). `Idempotency-Key` on every write, 24 hours per token, in `task_board_api_idempotency` or the host's store; `nowo:task-board:api:purge-idempotency`.
- **Task API, read endpoints.** Spaces, folders (none), lists, tasks with ClickUp's filters and 100 per page, a task, comments, members, tags, imported custom fields and time entries (through the host's time hook). Lists hold and count only what the token may read; each list answers in a fixed number of queries. See [API](API.md).
- **Task API, skeleton (`api.*`).** `GET /v2/user`, `GET /v2/team` under `api.prefix`; the request pipeline (failed-token limit, authenticator, security user, rate limit), `Api\Security\TaskApiGuard` (level ∩ access ∩ where), `Api\TaskApiCapability`, one `{err, ECODE}` shape. Hooks in `Api\Hook`, where every default denies; event `TaskBoardEvents::API_WRITE`. The build refuses `security.allow_unauthenticated` and, without `api.host_decides_access`, `security.resource_scoping: false`.
- `Service\TaskManager::restore()` and `archive()`, with their history; `restore()` asks the host's limit and throws `TaskReopenRefusedException` when it refuses.
- `Service\TaskBoardEraser::eraseTask()`: one task and its subtasks, leaf-first through Doctrine, tracked time kept and detached. Needs the board's manage decision; a task that follows a provider, or has a subtask that does, is refused with `TaskLinkedToProviderException`. The files a sync stored for the subtree, kept after an unlink, are named in each `TaskErasedEvent` and their rows removed, so the host can delete them (audit IA-M1).
- `Service\TaskBoardEditor::update()`: name and description of a board, the slug unchanged, `TaskBoardEvents::BOARD_UPDATED` (`BoardUpdatedEvent`).
- `Service\TaskCommentPolicy`: who may write, edit and delete a comment.
- `Validation\TaskConstraints`: the rules of the fields a write takes.
- `Service\TaskDependencyManager::findBetween()`, `Dto\TaskFormData::fromTask()`.

### Fixed

- `api.enabled: true` in one config file and `false` in a later one (a `when@prod` override) no longer maps the idempotency table or registers `NOWO_TB_JSON_TEXT`: `prepend()` follows the last value set, as the merged configuration does, and so does `api.hooks.idempotency_store` (audit IA-L8).

### Deprecated

- `sync` and `task_numbers` still map their tables when any config file enables them, even if a later override disables them (2.1 behaviour, kept so a layered config never drops tables holding data). Deprecated: 3.0 follows the merged value.

### Changed

- `TaskBoardManageController` asks `TaskCommentPolicy` and `TaskManager::restore()`, and the write forms take their rules from `TaskConstraints`. Their output does not change: `tests/Functional/Characterization` pins it. Its `$syncLimits` argument stays in place but is deprecated and ignored: `TaskManager` holds the same limit (audit IA-I1).

## [2.1.1] - 2026-11-07

Target: 2.1.1. Reported by Beacon on 2.0.2. No schema, configuration or permission change. Audit HZ (2026-11-07): Pass (good) / Low. See [UPGRADING](UPGRADING.md#to-211).

### Fixed

- The board index drew two New board buttons when there were no boards. There is now one: in the header when there are boards, otherwise in the empty state. The empty state takes the same check as the header did, so a list emptied by the list check or by board access keeps the button.
- New board is primary whatever the host's `nowo_ui_kit.action_variants.create` says. It is the page's main action, and an explicit variant is how UiKit keeps one (`Action::create()` does the same).
- The Board / List / Gantt buttons of the index end under their Views heading. On a phone, where cards replace the table, they stay at the start beside their label.

### Documentation

- `BoardScopeProviderInterface::scopes()` and `docs/CONFIGURATION.md` state what the code already does: any scope shows New board, so a provider returns `[]` on a tenant's page where the user may not create, and `canCreateBoard()` then decides. `[]` is not a denial: access stays the checker's (audit HZ-L2).
- The board index's block table in `docs/USAGE.md` lists the 2.0 blocks; it still named the 1.x ones.

## [2.1.0] - 2026-11-07

Target: 2.1.0, spec 214. Audit HY (2026-11-07): Pass (good) / Low at `3c105a1`. Opt-in: with `task_numbers.enabled` and `calendar.enabled` off (the defaults) no route, service, table or output changes, except the one column below. See [UPGRADING](UPGRADING.md#to-210).

### Changed

- A synced task with no start date keeps none, instead of storing its creation date as the start (audit HY-L3). The Gantt draws it as before. Tasks already synced keep what they have: see [UPGRADING](UPGRADING.md#to-210).

### Added

- **Task numbers** (`task_numbers.enabled`, `task_numbers.scope`): a readable `#123` per task, per tenant by default or per board. Assigned at persistence by a Doctrine listener, so the manager, the importers, the sync and its checklists all get one. Numbers come from a counter (`task_board_number_counters`) and a ledger (`task_board_task_numbers`): never reused, a deleted task's number retires, a task moved to another scope gets a new number and keeps the old one as an alias. The reservation commits in a short transaction of its own, on a second connection when the caller is in a transaction, so a sync page never holds a counter. `Task::getNumber()`, for the spec 213 serializers.
- `nowo:task-board:numbers-backfill`: numbers older tasks per scope in creation order. Resumable, and a no-op once none is left.
- `TaskNumberLedger`: `find()`, `findInScopes()` for a host's search, `purgeScope()` for a destroyed tenant, refused while its tasks still carry numbers (audit HY-L2).
- Route `task_by_number` (`/n/{number}` under each tenant path; `?board=` where no tenant names the scope). Unknown, retired and unreadable answer the same 404.
- The number on the card, the panel and task page (with *Copy*), the list (column, *Sort*, search), the subtask tree and the Gantt tooltip. Twig: `nowo_task_board_number()`.
- **Calendar view** (`calendar.enabled`, route `calendar`, `web_ui.templates.calendar`): month, week and list modes, the week start of `WeekStartResolverInterface`, everything in the URL (`mode`, `date`, `assignee`, `priority`, `tag`, `column`, `completed`, `subtasks`), a query bounded by the visible days and capped by `board.max_tasks`, and a count of undated tasks. On a phone it opens in list mode. `calendar` is a valid answer of `board.default_view_resolver`.
- **Reschedule**: route `task_schedule` and `TaskManager::reschedule()`, with the history and `TaskUpdatedEvent` of the task form. Drag the body (UiKit's `sortable`), drag an edge, or use the *Reschedule* dialog; arrow keys move between the days; a move shows *Undo*. No drag on a phone or a touch screen. A time of day is kept. Only years 1970 to 2200 are accepted, those the calendar shows (audit HY-L1). `task_create` comes back to the calendar with `?calendar=1` and `_target_path`.

## [2.0.2] - 2026-11-07

Target: 2.0.2. Audit HY (2026-11-07): Pass (good) / Low at `a510f4f`.

### Fixed

- The board index and the list page no longer answer 500 on a host with `nowo_ui_kit.icon_set: ux_icon` that imports Tabler only (`Icon "bi:plus" not found`, then `Icon "bi:chevron-up" not found`). The New board action asks for `tabler:plus` and the filter fold for `tabler:chevron-up` and `tabler:chevron-down` there. Output on a webfont host does not change.
- The all-digit checklist item fix of 2.0.1 survives `rector process`: the parent check reads the id from the item instead of casting the array key, a cast rector removes as useless. An all-digit item that names itself as parent stays on the list.
- `nowo_ui_kit.action_icons.create` now sets the icon of New board, whatever the icon set; `chevron_up` and `chevron_down` keep setting the filter fold's. The bundle's own `plus` used to win over the host's map.

### Documentation

- The list of Tabler icons to import on `ux_icon` (`docs/USAGE.md`) adds the ones it was missing.

## [2.0.1] - 2026-11-07

Target: 2.0.1. Audit HY (2026-11-07): Pass (good) / Low at `f2fa0d4`. Both bugs are from audit HY, and neither is a security issue.

### Fixed

- The board link and buttons on a phone: a long description no longer widens the card, whose label caught taps over them.
- A sync no longer fails on a remote task whose id is all digits. All-digit attachment ids and checklist item ids are handled the same way.

### Changed

- The sync's error logs add `at`, the file and line of an unexpected failure, beside its class. Never its message.

## [2.0.0] - 2026-11-07

Target: 2.0.0. Audit HX (2026-11-07): Pass (good) / Low at `08e51a4`. See [UPGRADING](UPGRADING.md#to-200-uikit-page-contract).

### Changed

- **BREAKING. UiKit page contract (T1-T7).** Requires UiKit `^2.37`. The pages fill the canonical blocks. The index, import, connection and task pages use the kit's list, form and show scaffolds; the rest are declared unique pages in [TEMPLATES](TEMPLATES.md). `web_ui.layout_template` and `web_ui.templates.<page>` replace `templates.layout` and `templates.<page>`, which stay deprecated; a pre-2.0 layout goes through a bridge until 3.0. `templates.css_framework` is ignored and `nowo_ui_kit` is no longer seeded.
- **BREAKING. `TenantAwareTaskBoardAccessCheckerInterface::canListBoards($user, $tenantKey)` (TB-8).** With a tenant, a refusal answers 404.
- All 15 dialogs use the kit's `dialog` partial and overlay registry. The five `window.confirm` calls use `nowoUiAskConfirm`. Alerts, avatars, the header, the Markdown description's fold (`show_more`) and the subtask tree (`tree`, every group open) come from the kit.
- `--nowo-task-*` tokens fall back to `--nowo-ui-*` instead of `--tblr-*`. Framework classes leave the markup, through theme slots.
- Flashes are translated when added.
- **BREAKING. Board, list and Gantt chrome (T6).** The views are header tabs, the Gantt scale is the kit's `tabs`, each list group is the kit's `table`, and the filters fold on UiKit's markup with a bundle controller that needs no host peer (`nowo-task-board-filter-fold`, replacing `nowo-task-board-filter-collapse`). A filter restored on Back or reload opens the fold.

### Added

- `TaskBoardEvents::QUOTA_CHECK` (`TaskBoardQuotaCheckEvent`), asked before a board, task, subtask or import writes (TB-9).
- `tenancy.open_single_board` and `tenancy.first_board_provisioner` (`FirstBoardProvisionerInterface`), TB-7.
- `import.help_link`, translated and permission-checked (TB-2).
- `templates.page_header`, `board.toolbar`, `task_panel.toolbar`, `data-nowo-task-board-open` (TB-10).
- `templates.task_time_before` (TB-3). Public hooks `data-nowo-task-title`, `data-nowo-task-column`, `data-nowo-task-panel` (TB-11).

### Fixed

- The import form printed FormKit's convention keys, such as `task_import_form.file.help`, as text with the seeded profile.
- The subtask fold threw on a task with no subtasks. A drop whose request does not finish reloads the board.

### Removed

- `layout.html.twig`, `layout_integrate_host.html.twig` and the Tabler CDN, `manage/_page_header`, `manage/_flashes`, the dead `task_board/index` and `assets/src/task-board.css`. Controllers and helpers `dialog-controller`, `modal-toggle`, `avatar-fallback`, `board-description-controller`, `filter-collapse-controller`.
- The `.modal` Bootstrap branch of the dialog controllers, kept for template overrides since 1.14.

### Not in this step

- Drag and drop moves to UiKit's `sortable` after the owner's test on a real device. TB-5, the `task_panel.mode: drawer` part of TB-11 and `multi-select` in the dependency picker move to 2.1.

## [1.30.0] - 2026-11-07

### Added

- **New board, generic parts of spec 211.** All opt-in under `board.*`:
  - `icon` (`TaskBoard::$icon`): one emoji of at most 16 bytes, stored apart from the name, shown before it in the header, lists and breadcrumbs. `icon_form_type` swaps the input for a picker; the constraints stay. A long joined emoji is refused with a message saying why.
  - `description_format: markdown` (`TaskBoard::$descriptionFormat`): rendered through the host's `TaskTextRendererInterface`, folded after three lines with *Show more*. Existing boards stay plain text.
  - `description_form_type` replaces the description editor with no Twig override.
  - `advanced_slug` folds the slug under *Advanced* and transliterates it.
  - `scope_provider`, `BoardScopeProviderInterface`: a scope step in the create form. The bundle draws the steps; the host supplies scopes and fields and resolves the tenant. The create check runs on the resolved tenant, and the redirect uses its slug.
  - `tenant_label_provider`, `BoardTenantLabelProviderInterface`: the tenant label and icon in board lists, one call per list. `TaskBoardTenant` gains `$icon`.
- `TaskBoardEvents::BOARD_CREATED` (`BoardCreatedEvent`), for every create. Twig `nowo_task_board_description()`, `nowo_task_board_label()`, `nowo_task_board_tenant_labels()`, partial `_board_title.html.twig`.

### Changed

- **Slugs are unique per tenant**, not globally. A global suffix told one tenant that another tenant had that slug. Schema change, see UPGRADING.
- `symfony/string` is a direct requirement, for transliteration.
- Audit HS (2026-11-07): Pass (good) / Low at `2515e98`. Open residuals are listed in [SECURITY](SECURITY.md#new-board-scope-step-since-130).

## [1.29.0] - 2026-11-07

### Added

- **`nowo:task-board:sync --time-entries`.** A command run can read tracked time (`SyncOptions::timeEntries`), which only code could ask for. It needs `sync.time_entries.sink`.
- **`SyncFieldValueEraser::eraseField($task, $fieldName)`.** Erases one field of one task without the value, for an erasure log that keeps none. After changing `kernel.secret`, run a full sync, then repeat it: before the sync there is no value to erase and it returns 0.
- **`TaskChangeRecorder::recordMember()`.** A member row names its member by id in `context` (`assignee user:7`).

### Changed

- **"Former person".** The labels of `task_board.time.former_user`, `task_board.history.former_user` and `task_board.sync.people.former_user` use the owner's term in all 7 locales. The keys stay.
- **The bulk *former* button** uses the `btn_secondary` slot, not a fixed class.

### Fixed

- **Quota on restore.** A completed task adds nothing to active tasks, so restoring it asks the limit for none: by hand, by the sync's scan, and by a page when the provider also says it is done. A task the provider reopens still counts.
- **Saving *Not mapped*** is a decision: the person leaves the summary's "not mapped yet" count and stays free to match by e-mail later. The bulk action still reaches them (`TaskSyncPersonRepository::unmappedCount()`).
- **Audit residuals.** The edited mark keeps `*`, `_` and `~` inside a link or image target. A value-alone suppression record ignores case, and records written by 1.28.0 still hold. The e-mail path's memo is skipped for a user with no id. `anonymise: true` clears member rows by the member's id; only rows from before 1.29 go by label (HId-L4).
- **Reopening asks the limit (audit HN-M1).** A move or a provider status that reopens a completed task asks `SyncLimitCheckerInterface` for one active task; a refused move stays where it was, and the sync's page is refused. `POST .../move` answers 409 to a fetch. Deleting a column that would reopen completed tasks asks too, and a refusal keeps the column (audit HNd-L2). File imports and a provider's checklist item stay free, as documented.
- `--time-entries` warns when it cannot run. Member rows are cleared by exact context, not `LIKE` (audit HN-L1). The edited mark also compares every address as a set (audit HN-L3). A changed e-mail makes a reviewed person due again (audit HN-L4).
- Audit HN (2026-11-07): Pass (good) / Low at `cc80289`. Open residuals are listed in [SECURITY](SECURITY.md#personal-data-in-synced-tasks-rgpd--lopdgdd).

## [1.28.0] - 2026-11-06

### Fixed

- **Sync writes by e-mail (audit HI-M2).** With no people session or no person reference, a match by e-mail or name is written as member or comment author only if it can view the board and is not attribution only. From 1.23.0 to 1.27.0 that path did not ask, and a member row gives the task. See [SECURITY](SECURITY.md#sync-writes-by-e-mail-fixed-in-1280) for what a host can do before upgrading.
- **Unmatched columns lock (audit HI-M1).** `SELECT … FOR UPDATE` on the column row. CI gains a PostgreSQL job for the functional suite.
- **Fields document (audits HI-L2, HI-L3).** Marked when the sync writes it (`task_board_task_documents.sync_fields`, schema change). Erased lines do not return on a re-sync, and the exports read the lines.
- **History values (audit HI-L4).** `anonymise: true` also clears `member_added` and `member_removed` values equal to the user's current label.
- **Edited mark (audits HI-L5, HId-L1).** A comment whose text, link targets and numbers did not change is rewritten without the mark.
- Tag colours use `data-nowo-task-vars` instead of a `style` attribute (audit HI-I3).
- Audit HI (2026-11-06): Pass (good) / Low at `532dbaa`. Open residuals are listed in [SECURITY](SECURITY.md#personal-data-in-synced-tasks-rgpd--lopdgdd).
- **ClickUp comments keep their style (TB-SYNC-4).** `Sync\Provider\ClickUp\ClickUpCommentRenderer` replaces the private
  `segmentsToMarkdown()`: lists (bullet, ordered, checked, unchecked, toggled) nested by `indent`, fenced code blocks,
  `strike`, `link_mention`, `task_mention`, `emoticon`, and other typed segments with a target. ClickUp documents a Quill
  delta (block attributes on the line feed); the attribute on the text and flat values are read too, since live data has
  not been checked. `underline`, `color` and `background-color` keep the text, not the style: the host escapes raw HTML.
  Every link and image goes through `RemoteContentSanitizer::isSafeUrl()`; a refused target leaves the label as text.
  A comment whose segments give no text falls back to `comment_text`.
- **Open questions on ClickUp, handled both ways.** A task whose `GET task/{id}` has no `attachments` key no longer stays *Not read* forever (audit
  GWf-I1): it stays so on the first read, a full sync asks again, and a second answer without the key counts as no files (ClickUp documents the key for tasks with files only). No stored file is touched. An already read version still reads a thread whose
  `reply_count` is above the replies kept here, so a reply that does not move `date_updated` arrives on a full sync (audit GWf-I2).
  `sync.full_sync_rereads_details` (default `false`) does the same for files, at the cost of the detail budget.
- **Audit residuals.** GWf-L1: `TaskChangeHistory.user` is `ON DELETE SET NULL` with a `user_name` snapshot, so a history row, `time_removed` included, outlives its
  author's account; `detachUser()` detaches it, and with `anonymise: true` forgets the name. GWd-L4: `eraseByValue()` and `eraseBoard()` also remove the lines of the *Fields* document
  in `text` and `both` modes, recognised by its shape, so a document somebody wrote is untouched. I6: `exportPerson()` adds `tasksCreated`, `commentsWritten` and `taskRoles`.
  *UPGRADING* no longer tells hosts to call TimeTrack's `removeForUser()` during account erasure, which deletes time.
- **`.nowo-task-btn-danger` no longer sets `color` and `border-color`.** They went over the `btn_danger` slot, giving red on red with a solid slot. The slot alone decides the colours; the
  class stays for hosts that hook on it.

### Added

- **Former people's hours (TB-SYNC-4).** `Sync\Hook\OwnerlessTimeEntrySinkInterface`: a sink implementing it also receives the
  entries of people with no host user. `ImportedTimeEntryCandidate::user()` is null, `isOwnerless()` true, and
  `$person` and `personName()` say whose they are. A sink that only implements `ImportedTimeEntrySinkInterface` behaves as
  before; its skipped entries now appear in the completeness report with their hours (`time_entries_unmapped`).
- **Bulk former people.** `sync.people_bulk_former` (default `false`) adds *Mark every unmapped person as a former person*
  to the People page and the POST route `board_connection_people_former`, with its own CSRF id. Mapping `external`, a human
  decision the sync never overrides, changeable person by person. Dispatches `PeopleMappingChangedEvent`.
- **Attribution only.** `Sync\Hook\AttributionOnlyResolverInterface`, optional on the host's person resolver, flags a candidate
  such as a suspended member. A person mapped to them shows the user's label (`SyncPersonView::$attributionOnly`) and the
  sink gets the user, but they are never written as creator, author or member, whatever the board access check says.
- **Columns no status uses (TB-SYNC-4).** `ConnectionLinkFormType::unmatchedColumns`, `keep` (default) or `delete`, accepted when
  posted; `sync.unmatched_columns_choice` (default `false`) draws it on the bundle's link page. `delete` acts once per connection,
  marked on the connection, at the end of the first import when every slice completed without error. Only empty columns go: any task
  row counts, and check and delete share a transaction. A column holding tasks stays and is listed in the run report; a board keeps one
  column; tasks never move; later syncs never delete a column; same permission as *Delete column*, logged as `column_delete` with
  `origin: sync_unmatched_columns`. New event `TaskBoardEvents::SYNC_UNMATCHED_COLUMNS_RESOLVED`. Counted in
  `columns.unmatchedDeleted` and `columns.unmatchedKept`.
- **Everything possible from ClickUp (TB-SYNC-3 addendum).** Each checked against ClickUp's published API reference, not a live
  workspace. Task types: `custom_item_id` and the workspace's names (`GET team/{id}/custom_item`, one call per run, optional
  `TaskTypeProviderInterface`). All of a task's lists (`locations`), and `sync.include_multi_list_tasks` (default `false`) sends
  `include_timl` on list sources and on the deletion scan. Tag colours in the task panel. Status colours and order on the columns the
  connection created, once, at the end of the first import. The list's description on the connection page. New
  `ProviderApiGapsInterface`: reminders, sprints, goal links, folder and space descriptions are reported as *not exposed by the API* on every
  run, comment `assigned_by` and `reactions` as not supported; none is marked imported. Subtasks at any depth, closed ones too, are covered by a test.

## [1.27.0] - 2026-11-06

### Added

- **Typed custom fields (TB-SYNC-3).** Board-level definitions (`task_board_sync_fields`) and typed values per task
  (`task_board_sync_field_values`) for text, number, money, date (with time), dropdown, labels, checkbox, URL, e-mail,
  phone, rating, progress, people and relationship; unknown types and values that do not fit are kept as text, never
  dropped. Shown in the task panel (`_task_sync_fields.html.twig`, Twig `task_board_sync_fields()`) and found by the
  list search (`data-search-extra`, Twig `task_board_sync_search_texts(tasks)`). `sync.custom_fields.mode`
  (`typed` default, `text`, `both`): the 1.23 "Fields" document is kept as an option. New
  `Sync\Provider\RemoteCustomField`, `RemoteTask::$customFields`, `Sync\Field\SyncFieldType`,
  `Sync\Provider\ClickUp\ClickUpCustomFieldParser`, `Sync\Field\SyncCustomFieldWriter`.
- **Comments in full (TB-SYNC-3).** Threaded replies, the files of each comment (copied with the attachment rules and
  linked under the comment), assigned comments with their resolved state, and the remote edit date
  (`RemoteComment::$parentId`, `$attachments`, `$assignee`, `$resolved`, `$updatedAt`; Twig
  `task_board_sync_comment_meta()`). Reactions are not imported.
- **Time entries**: `ImportedTimeEntryCandidate::user()` (the mapped host user). Imported time is never deleted.
- **The rest of a ClickUp task (TB-SYNC-3).** Custom task id (searchable), points, done / closed dates (the done date
  is the completion date), start / due time flags, location (folder, list), estimate per assignee, recurrence and the
  time in status (`Sync\Provider\StatusHistoryProviderInterface`, `sync.status_history.max_calls_per_run`), kept
  read-only in `task_board_sync_items.remote_meta` and shown in the task panel (`_task_sync_details.html.twig`, Twig
  `task_board_sync_details()`). Linked tasks become `related` relations (also in file imports). Archived tasks are
  imported archived (`sync.import_archived`, default `true`; ClickUp reads an archived pass). New
  `RemoteTaskDetails`, `RemoteStatusTime`, `RemoteTask::$details` / `$presentKeys`, `RemoteTaskPage::$passEnded`,
  `Task::markCompleted(?$at)`. A lasting error of the time in status (`statusHistory.failed`) keeps the last one and
  never fails a run; a passing one defers the task.
- **People in read-only data (TB-SYNC-3).** Estimates per assignee and `people` custom fields store references
  (`Sync\People\PersonToken`: `person:<id>`, or `ref:<provider user id>` without a directory), never a name or an
  e-mail; names are read from `task_board_sync_people`, so `anonymise()` also covers them. New
  `SyncPeopleViewProvider::personLabel()`.
- **Completeness report (TB-SYNC-3).** After each run the connection page lists every key of the answers of the tasks
  it read (written or not, at most 200 keys per run) with a value: imported, skipped (reason) or *Not imported yet*
  (`Sync\Provider\FieldCoverageProviderInterface`, `_connection_completeness.html.twig`).
- **Schema change with `sync.enabled` only**: two new tables, four nullable columns on `task_board_sync_comments` and `task_board_sync_items.remote_meta`
  (DDL for MySQL, PostgreSQL and SQLite in [UPGRADING](UPGRADING.md#to-1270)).

- **People page and step (TB-SYNC-2).** Routes `board_connection_people` / `board_connection_people_save`, templates
  `connection_people` / `connection_people_summary`, form `ConnectionPeopleFormType`, service
  `Sync\People\PeopleMappingService`: every remote person of the connection with a host-user select fed by the host's
  candidates (`SyncPersonResolverInterface`), pre-filled by e-mail; a same-name candidate is only suggested beside an unselected select (deny by default: only a
  save maps). Saving re-applies the change to the tasks already imported (creator, assignees, watchers, comment
  authors, checklist items; the host moves time on `SYNC_PERSON_REASSIGNED`) within `sync.people.reapply_seconds`; the
  rest continues on the next save and after every sync. `sync.people.step` (default `false`) puts the page between
  linking and the first import. Optional `sync.people.inviter` (`Sync\Hook\PeopleInviterInterface`) adds *Invite by
  e-mail*. New event `SYNC_PEOPLE_MAPPING_CHANGED` (`PeopleMappingChangedEvent`, ids only). The connection page shows
  mapped / not mapped people with a link; the progress JSON gains `unmappedPeople`. Config `sync.people.step`,
  `inviter`, `page_size`, `candidate_limit`, `reapply_seconds`. `TaskSyncPersonRepository::pendingReassigns()`.
  No schema change.

### Fixed

Audit GW (2026-11-06): Pass (good) / Low, re-verified at `0009885`. Open residuals are listed in [SECURITY](SECURITY.md#personal-data-in-synced-tasks-rgpd--lopdgdd).

- **Deleting a user keeps their manual time (owner rule, GWd-M2).** `task_board_task_time_entries.user_id` is nullable,
  `ON DELETE SET NULL`, with a name snapshot (`user_name`). `TaskTimeEntry::getUser()` may return null. `detachUser()` of
  the people reassigner and the new `TaskTimeEntrySnapshotPurger::detachUser()` forget the name on request.
- **Removing a manual time entry (GWd-M2).** Only its author or a manager of the board; when the author no longer exists, a
  manager only. **Behaviour change**, see UPGRADING.
- **Files after an unlink (GWd-M1, also in 1.26.3).** The rows that track stored files stay through an unlink, so a later
  deletion of the task or board names the files to the host.
- **A lasting error on a task's detail (GWd-L1)** no longer stops the board: later tasks continue, the watermark moves,
  and the report shows `task_detail` as *Unavailable*.
- **Suppression record (GWd-L2).** Keyed with a subkey of `kernel.secret`, over the stored value
  (`Sync\Field\SyncFieldSuppression`). `people` and `checkbox` values no longer stay erased after a remote change.
- **Unread state (GWd-L3).** `task_board_sync_items.pending_reads` keeps what each task has unread, so the report no longer
  depends on the latest run. Read markers per version let repeated full syncs reach the next tasks, for threads and for
  details, whatever the rows lack.
- **"Fields" document (GWd-L4).** A `users` value holds names only, no e-mails.
- **`TaskSyncPersonRepository::exportPerson()` (I6):** one person's estimates, comment assignments and people fields.
- **`SyncFieldValueEraser::eraseByValue()` / `exportByValue()`** take the board explicitly: `null` crosses every tenant.
- **Release note (GW-L2):** boards synced by 1.26 and earlier that had a failed run may be missing comments, files and
  fields. One full sync repairs them.

- **List memory (GW-M1).** The list reads custom fields only for the rows drawn and never reads `remote_meta`: the custom
  id has its own column, `task_board_sync_items.custom_id`. Twig `task_board_sync_search_texts(tasks)`.
- **Comment files (GW-M2).** They are tracked in `task_board_sync_attachments`, so erasure names them, an unchanged file
  is reused, and a replaced or removed one leaves the storage. Nothing is downloaded for a comment in conflict.
- **Personal data in field values (GW-M3).** `sync.custom_fields.exclude_types` (default: none, everything is imported),
  `Sync\Field\SyncFieldValueEraser` (`eraseByValue()`, `eraseBoard()`, `exportByValue()`, `exportBoard()`). A malformed
  people value is no longer kept as text.
- **Rejected URLs (GW-L1).** A value that does not fit its type never redefines the board's field, and the view
  re-checks it before drawing a link.
- **Lost tasks after a provider error (GW-L2, also in 1.26.3).** Every task of the page not finished is read again by the
  next run, not only the one that failed.
- **People page.** A same-name candidate is no longer selected (GW-L3), and a row absent from the POST stays as it is
  (GW-L4).
- **Completion date (GW-L5).** A remote done date applies only to a task the sync completed, or whose date has not
  changed since (`details.completionKept`).
- **Budgets and bounds.** `sync.comments.max_reply_calls_per_run` (GW-L6); a reply 404 no longer fails the run (GW-I9);
  dates outside 2000-2100 and people ids over 64 characters are refused (GW-L7); restoring a task asks the host's limit
  (GW-L8); the `mailto:` value is encoded and `?` or `&` in an e-mail is refused (GW-L9).
- **Task files on list rows.** ClickUp list rows have no `attachments`: the task's detail is read, within
  `sync.watchers.max_detail_calls_per_run`. The tasks left are reported as not read, not as imported
  (`RemoteTask::$attachmentsKnown`, `attachments.unread`).
- **Time in status refused (403).** The requests stop for the run after the first refusal, and the report says
  *Unavailable*.

## [1.26.3] - 2026-11-06

The connection badge data carries the running sync's progress (`activeRunId`, `progressUrl` for managers, `total`, `processed`); opt-in `sync.badge.progress` draws a determinate bar in the bundle's badge.

### Added

- **The running run in the header badge (TB-SYNC-1).** `nowo_task_board_connection(board)` (the board, list and Gantt
  header badge) also returns `activeRunId`, `progressUrl` (route `board_connection_progress`, only for users who may
  manage the connection), the run's `total` / `processed` and `pollInterval`, so a host badge can drive the
  `nowo-task-board-sync-progress` controller: a determinate bar, a polite live region (at most one update every 5 s) and
  *Show the result* when the run ends, without reloading. New option `sync.badge.progress` (default `false`): `true`
  renders that bar in the bundle's own badge; the default output is unchanged. `SyncReport::processed()` (written +
  skipped) is the bar's numerator, also used by the progress endpoint.

## [1.26.2] - 2026-11-06

The Import subtasks dialog follows the import permission (ACTION_IMPORT_TASKS), as the import action already did.

### Fixed

- **The task panel's "Import subtasks" dialog now follows the import decision.** The task view built
  `subtaskImportForm` from `canEditTask()` instead of `canImportIntoTask()`, so an editor denied
  `BoardAccessCheckEvent::ACTION_IMPORT_TASKS` (Beacon's `project.tasks.import`) still saw the dialog. It is now
  offered only when `canImportIntoTask()` allows it. UI-only: the `importIntoTask` action already enforced
  `canImportIntoTask()` and answered 403, so no import ran without the permission.

## [1.26.1] - 2026-11-06

Manual and calendar entries from TimeTrack 1.14 (`TIME_ENTRY_CREATED`) now add to the task's time spent, like timer stops.

### Fixed

- **Manual and calendar time now reaches the task total** (TimeTrack contract §9.3). With TimeTrackBundle ≥ 1.14,
  `TimeSpentAggregatorListener` also listens to `TimeTrackEvents::TIME_ENTRY_CREATED` (`TimeEntryCreatedEvent`,
  origin `manual` or `calendar`: `POST entries` and accepted calendar suggestions) and adds the entry's duration to
  `Task::$totalTimeSeconds`, reusing the `TIMER_STOP` path (refresh under a kept-alive worker, then add). The tag is
  added by the extension only when the event class and constant exist, so TimeTrack 1.10–1.13 and hosts without
  TimeTrack behave as before. TimeTrack dispatches each entry through exactly one of `TIMER_STOP` and
  `TIME_ENTRY_CREATED`, so nothing is counted twice. Entries on tasks that are not TaskBoard tasks, and zero-length
  entries, are ignored without a query or an error.

## [1.26.0] - 2026-11-06

Dialog rule v2: all 15 native dialogs get a header with ×, a footer outside the body and one primary, with kit size tokens; footer buttons use UiKit theme classes when available.

Every dialog follows the owner's dialog rule v2: header with ×, a body that alone scrolls, the footer outside it with
one primary, the kits' two widths and a content height.

### Changed

- **Dialog anatomy (visual change).** The actions of every native dialog moved out of the scrolling form into a
  tinted footer (`nowo-task-dialog__footer nowo-task-dialog__actions`), the last child of the `<dialog>`: the submit
  buttons name their form with `form="…"`, so header and footer stay in view and only the body scrolls. New form ids:
  `nowo-task-board-create-form`, `nowo-task-board-add-task-form`, `nowo-task-board-add-column-form`,
  `nowo-task-board-edit-column-form`, `nowo-task-board-column-delete-form`, `nowo-task-board-priority-form-<task>`,
  `nowo-task-column-set-save-form`, `nowo-task-column-set-apply-form`, `nowo-task-dependency-form-<task>`,
  `nowo-task-time-form-<task>`, `nowo-task-subtask-import-form-<task>`, `nowo-task-sync-unlink-form`,
  `nowo-task-sync-mass-archive-form`. Dialogs: New board, Add task, Add / Edit column, Save columns as set, Add columns
  from set, People, Priority, Add / edit link, Add dependency, Log time, Import subtasks, Import help, Unlink, Archive
  anyway. Existing ids, `data-testid`s, Stimulus targets and form field names are unchanged.
- **Edit column has one primary.** *Delete column* is no longer a second block with its own button under the form: it
  is a danger-outline button on the left of the footer, bound to the (now hidden) delete form, which keeps its
  confirmation; the help sentence moved into the body (`aria-describedby`).
- **Add columns from set** has Cancel and *Add columns* in the footer (Close only when there are no sets); the apply
  form's submit left the body.
- **Sizes from the kits' tokens.** `dialog.nowo-task-dialog--native` is `--nowo-dialog-width-md` wide
  (`nowo-task-dialog--wide`: `--nowo-dialog-width-wide`), its height follows the content capped at
  `--nowo-dialog-height-md` / `-wide` and the viewport minus 2rem (UiKit's defaults as fallbacks). The bundle's own
  sizes are gone: 32rem / 40rem widths and the import help body's `max-height: min(70vh, 40rem)`. Header and footer
  are tinted (`--nowo-task-surface-muted`) with a divider.
- **Footer buttons from the theme.** Cancel / Close, the primary and the danger actions read the UiKit theme slots
  `btn_secondary`, `btn_primary`, `btn_danger`, `btn_danger_solid` of the theme bundle `NowoTaskBoardBundle` (new Twig
  function `nowo_task_board_btn_class(slot)`); without UiKit's theme extension the 1.25 Bootstrap classes stay.
  With UiKit 2's theme extension the buttons carry UiKit's classes (`nowo-ui-btn nowo-ui-btn--secondary` … next to
  the Bootstrap defaults, or only the host's theme classes with `framework_defaults: false`): a host that does not
  load UiKit's CSS (`nowo-ui.css`) may see the footer buttons change. A slot the theme cannot resolve falls back to
  the 1.25 classes and logs a warning (`logger`, when available).
- **CSS:** the `.nowo-task-dialog__danger` rules are removed (no template renders the class since Edit column's
  Delete moved to the footer); `.nowo-task-dialog__danger-help` stays.

### Added

- `manage/_board_create_form.html.twig`: `with_actions` (default `true`) and the form id
  `<id_prefix>nowo-task-board-create-form`; with `in_dialog` the action row carries `data-nowo-ui-dialog-actions` (a
  host's UiKit remote dialog moves it into its footer).

## [1.25.2] - 2026-11-05

Option `sync.badge.connect_link` to hide the connect link of an unlinked board.

### Added

- **`sync.badge.connect_link`** (bool, default `true`): `false` hides the connection badge of an **unlinked** board on
  the board, list and Gantt headers — no *Connect provider* link and no empty strip — without overriding
  `templates.connection_badge`. A linked board keeps its badge (provider · list, last sync, *Refresh*, *Connection*).
  Exposed to Twig as the global `nowo_task_board_sync_badge_connect_link`. The default output is byte-identical to
  1.25.1.

## [1.25.1] - 2026-11-05

Patch release. See [UPGRADING](UPGRADING.md#to-1251).

### Added

- Task page: stable task-page hook for integrations — `manage/task.html.twig` wraps its body in
  `<div class="nowo-task-page" data-task-id="…">` (the panel fragment keeps its own `.nowo-task-panel-fragment` hook).
  No layout or CSS change.

## [1.25.0] - 2026-11-05

ClickUp full import for provider sync (`sync.enabled` only; contract v1.0 with Beacon spec 208): people, creator and
creation date, assignees and watchers, checklists, comment authors, status types, tracked time through a host sink, and
re-association. Hosts without `sync.enabled` see no schema or behaviour change except the new `import_tasks` access
decision (granted by default).

Also in 1.25.0 (contract v1.0 §15.6 / §10.3, and the 1.23.2 / 1.24.0 audit follow-ups): the board eraser and the
imported-time task totals. **Owner rule: deleting tasks or boards keeps all tracked time.** See
[UPGRADING](UPGRADING.md#to-1250).

### Added

- **People directory** (`Sync\People\TaskSyncPeopleDirectoryService`, tables `task_board_sync_directories` /
  `task_board_sync_people` / `task_board_sync_members`): one row per remote person per (host scope, provider,
  workspace), shared by every board of the scope. Mapping states `unmatched`, `suggested`, `auto`, `manual`, `external`;
  `applyMapping()`, `markReviewed()`, `anonymise()` ("Former <provider> user #n"), `pruneDirectory()` on the last
  unlink. Many remote identities may map to one user; one identity never maps to two.
- **Host hook `sync.people.resolver`** (`Sync\Hook\SyncPersonResolverInterface`: `directoryScope()`, `candidates()`,
  `isCandidate()`, `autoMatch()`, `candidateById()`). Without it, `DefaultSyncPersonResolver` keeps 1.23 e-mail
  matching with one directory per board. `autoMatch($board, $person, ?string $normalizedEmail = null)`: the bundle
  passes the normalised e-mail; without it the resolver derives it from the person.
- **Automatic e-mail matching** only creates a mapping for a person no human decided: a verified account → `auto`, an
  unverified one → `suggested` (needs a confirmation). A changed e-mail never re-maps automatically.
- **Projection rule:** a mapped user who cannot view the board is never written as creator, member or comment author
  (a mapping never grants access); the person stays a reference shown with a *no access to this board* badge.
- **Creator and original creation date** of synced tasks (`Task` / `TaskComment` take an optional `createdAt`); tasks
  written by 1.23 are back-filled once on their next fetch (`task_board_sync_items.backfill_level`).
- **Assignees and watchers** through the directory. Members the sync adds are tracked in `task_board_sync_members`;
  only those are ever removed. Unprojected people are listed per task (`unmatched_people`, *Also assigned in ClickUp*).
- **Checklists** become subtasks (`<task>#<item>`, tag `checklist:<name>`, nested items flattened in tree order); the
  deletions scan never archives them, a checklist that drops an item does.
- **Comment authors** as person references (`task_board_sync_comments.author_person_id`) and the ClickUp comment date.
  `TaskSyncCommentLegacyAuthorMigrator::migrate()` strips the 1.23 "**name** (ClickUp):" line once and returns a
  `MigrateReport` (`connectionId`, `directoryId`, `migrated`, `skipped`, `lastId`, `done`).
- **Status types decide "done"** (`closed` / `done`); the last-column rule is only a fallback without types. A
  done → not-done change reopens the task.
- **Status → column map** on the connection (`TaskBoardSyncService::link(..., $statusColumnMap)`,
  `updateStatusColumnMap()`): a column id, `__create` (created when the link is confirmed) or `__skip` (not
  imported, counted `skipStatus`). Unmapped statuses follow the 1.23 rules with the warning `unmapped_status`.
- **Folder and space sources** (`f:<id>` / `s:<id>`, `Sync\Provider\SourceProviderInterface`), `resolveListFromUrl()`
  (app.clickup.com list links, no network call) and `searchLists()` (≤ 40 calls, optional PSR-6 cache
  `sync.lookup.cache_pool`, keyed by a hash of the connection reference). `sync.list_as_tag` adds `list:<name>`.
- **Task detail fallback (E5)** `Sync\Provider\TaskDetailProviderInterface`: rows without watchers or checklists are
  completed by `GET task/{id}` for tasks created or changed in the run (`sync.watchers.max_detail_calls_per_run`).
- **Tracked time through a host sink** (`sync.time_entries.sink`, `Sync\Hook\ImportedTimeEntrySinkInterface`): a
  `time_entries` phase reads 30-day windows (halved down to one hour above `max_entries_per_window`), per assignee chunk
  with an admin token, the token user's own entries with a member token (warning `time_member_mode`), and the legacy
  per-task intervals only on request (`SyncOptions::$timeFallbackPerTask`). Running timers and task-less entries are
  counted, not delivered. The bundle never writes TimeTrack data.
- **Typed run counters:** `SyncReport::$people` (`PeopleCounts`: `seen`, `created`, `autoMatched`, `suggested`,
  `unmatchedNew`, `changedEmail`, `autoUnmatched`) and `SyncReport::$time` (`TimeCounts`: `mode`, `windows`,
  `windowsSplit`, `entries`, `skippedRunning`, `skippedTaskless`, `fallbackTasks`); `SyncReport::$stats` keeps only the
  other counters (`checklist.*`, `detail.calls`, `skipStatus`).
- **Per-run options** `SyncOptions::$watchers` / `$checklists` (default `true`, on top of `sync.watchers.enabled` /
  `sync.checklists.enabled`).
- **Pre-scans for the connection dialog:** `TaskSyncPeopleScanner::scan()` (members, first task pages, first comments,
  one time window; ≤ 15 calls) and `TaskSyncSourceScanner::scan()` (source, workspace, statuses with types, people with
  `boardAccess`, time mode).
- **Re-association:** `TaskSyncPeopleReassigner::reassign()` / `cancelReassign()` / `detachUser()` (account erasure)
  and `TaskSyncPeopleProjector::reprojectForUser()` (called by the host when someone gains board access). Batched,
  resumable with a cursor, under each board's sync lock, no notification.
- **Events** `SYNC_PERSON_MAPPED`, `SYNC_PERSON_REASSIGNED`, `SYNC_PERSON_ANONYMISED`, `SYNC_PEOPLE_REVIEWED`,
  `SYNC_PEOPLE_REPROJECTED` (ids only) and the history type `sync_person_reassociated` (`person:` / `user:` tokens
  rendered at display time).
- **View models and Twig functions** `task_board_sync_creator(task)`, `task_board_sync_comment_author(comment)`,
  `task_board_sync_unmatched(task, role)`, `task_board_sync_token(token)`; the task panel and comments show them.
- **Export helpers** `TaskSyncPersonRepository::exportFor($user)` / `exportDirectory($directory)`.
- **People page filter** `Sync\People\PersonFilter` for `TaskSyncPersonRepository::forDirectory()`: mapping states, `q`
  (name or e-mail), `changed` and `changedSince` (e-mail changed in the provider).
- **Import access decision:** `BoardAccessCheckEvent::ACTION_IMPORT_TASKS` (`import_tasks`, granted by default),
  `TaskBoardResourceAccessChecker::canImportTasks()` / `canImportIntoTask()`; file imports and
  `canManageBoardConnection()` ask it.
- Config: `sync.people.*`, `sync.watchers.*`, `sync.checklists.enabled`, `sync.time_entries.*`, `sync.list_as_tag`,
  `sync.lookup.cache_pool` ([CONFIGURATION](CONFIGURATION.md#provider-sync-since-123)).
- `Service\TaskBoardEraser::emptyBoard()` / `deleteBoard()`: empty or delete a board in resumable slices (`EraseCursor`,
  `EraseProgress`). The provider connection is unlinked first, tasks go leaf-first in flushed batches (default 200, time
  budget 20 s), then `deleteBoard()` removes the columns and the board. Manual time entries are detached (never deleted)
  and TimeTrack entries are never touched. Authorised by the new
  `TaskBoardResourceAccessChecker::canManageBoard()` (board creator or `security.manage_roles`, then
  `BOARD_ACCESS_CHECK` action `manage`); `AccessDeniedException` otherwise.
- Events `TASK_ERASED` (`TaskErasedEvent`: task id, board id, title, the storage refs of the files a sync stored, the
  number of manual time entries) before each batch is flushed, and `BOARD_EMPTIED` / `BOARD_DELETED`
  (`BoardErasedEvent`: board id, counts, actor id) once at the end.
- `Bridge\TimeTrack\ImportedTimeAggregatorListener`: with TimeTrack ≥ 1.11 the task total follows imported entries
  (`TIME_ENTRY_IMPORTED` adds `duration − previousDuration`, moves between tasks; `TIME_ENTRIES_REMOVED` subtracts per
  task). Not registered with TimeTrack 1.10. New `@internal Task::subtractTimeSeconds()` (clamped at 0).
- `Service\TaskTimeEntrySnapshotPurger` (audit FA-L2 / EX-D2): count and clear the task title / external id snapshots
  of detached manual time entries (per board, logged before a date); the time itself stays.
- Index `task_board_tasks_board_open_due_idx` `(board_id, completed_at, due_at)` for due-date scans of open tasks
  (KIT-74; DDL in [UPGRADING](UPGRADING.md#schema-change-for-every-host-task-creator-and-comment-author-audit-fp-m1)).
  The other KIT-74 item, `help_link.anchor_from: source` on the import help link (board import and the subtask
  import of `_task_subtask_import`), already shipped in 1.24.0.

### Changed

- With `sync.enabled`: new comments have no author prefix line; synced tasks keep the provider's creation date; with
  `sync.people.require_verified_email: true` (default) e-mail matches of users without `isVerified()` are only
  suggested; non-viewers are no longer made assignees. See [UPGRADING](UPGRADING.md#to-1250).
- `ProviderHttpClient` maps HTTP 410 to `not_found` (like 404).
- `TaskBoard::$tasks` no longer uses `orphanRemoval` (audit FA-L1): removing a task from the collection inside a flush
  skipped the `PreRemove` time detach. Delete tasks with `EntityManager::remove()` or the eraser.
- The connection page's link, refresh and progress actions refuse (403, logged `actor_id_missing`) a logged-in user whose
  id cannot be read, instead of running the sync as the board's linker (audit EX-D1).
- `require-dev` `nowo-tech/time-track-bundle` `^1.11` (the bundle still works with TimeTrack 1.10: `suggest` only).

### Fixed

- PostgreSQL: `TaskRepositoryInterface::findTrackableForUser()` and `findByHostObject()` failed with *could not
  identify an equality operator for type json* (`SELECT DISTINCT` over the task row and its json `tags`); they now
  filter by an id subquery.
- `templates.connection_badge` is used by the board, list and Gantt pages (they included the bundle's partial by a
  fixed path, so the option had no effect there). New Twig global `nowo_task_board_connection_badge_template`.
- `TaskBoardSyncService::run()` hands the access check, its `BOARD_ACCESS_CHECK` listeners and `SYNC_STARTED` the
  managed actor when the caller passes one detached by an earlier `EntityManager::clear()`. With Doctrine ORM 3 native
  lazy objects (PHP 8.4+), a listener reading a detached, uninitialised user proxy threw
  `EntityIdentityCollisionException`.

### Deviations from contract clickup-import-1.24 v1.0 (accepted by the owner)

- `TaskSyncPeopleReassigner` / `TaskSyncPeopleProjector` return `ReassignReport` / take `ReassignCursor` (no separate
  `ProjectionReport` / `ProjectionCursor`).
- `SyncPersonResolverInterface::autoMatch()` has a third, optional `?string $normalizedEmail = null`; the contract's
  two-argument call works.
- There is no separate `people` phase: the workspace members are refreshed at the start of the tasks phase.
- Folder sources read the folder's lists with ClickUp `project_ids[]`.

### Security

- A mapping never grants access (projection rule); automatic matching needs a verified e-mail from the host and is
  scoped to its candidates; re-association is explicit, re-checked per board and audited by events.
- Every provider call stays `GET` to the constant ClickUp base URI; no token, avatar URL or time-entry description is
  stored or logged by the bundle; person names and e-mails stay in the person rows and are never logged.
- **Erasing an account never deletes boards, tasks, documents or comments** (audits FP-M1, FP-L7).
  `task_board_boards.creator_id`, `task_board_tasks.creator_id`, `task_board_task_documents.creator_id` and
  `task_board_task_comments.author_id` are nullable `ON DELETE SET NULL` with a name snapshot (`creator_name` /
  `author_name`); memberships, the user's history entries and their own manual time still go with the account;
  **schema change for every host** (on SQLite run the rebuild with foreign keys off, FP-L6), SQL in [UPGRADING](UPGRADING.md#schema-change-for-every-host-task-creator-and-comment-author-audit-fp-m1).
  `TaskBoard::getCreator()`, `Task::getCreator()`, `TaskDocument::getCreator()` / `TaskComment::getAuthor()` return
  `?object`; new `getCreatorName()` / `getAuthorName()`. `TaskSyncPeopleReassigner::detachUser()` also detaches every
  board, task, document and comment of the user (new `ReassignReport::$boards` / `$documents`); before, an unlinked
  board's imported tasks with their subtree, or a creator's whole board, were deleted with the user. A user deleted
  without `detachUser(anonymise: true)` keeps their name in the snapshots (FP-I11).
- **Checklist subtasks go through the host's quota** (audit FP-L1): `sync.limit_checker` and the new
  `sync.checklists.max_items_per_run` (default 2000); held-back items come with a later run.
- **LIKE searches escape with `!` everywhere** (audits FP-L2, FP-I12): `PersonFilter::$q`, the checklist prefix
  queries and the "my tasks" title search (`findTrackableForUser()`) use `ESCAPE '!'` on MySQL, PostgreSQL and SQLite; `first_last@…`, `%` and `!` match literally.
- **The time phase has a call budget** (audit FP-L4): `sync.time_entries.max_calls_per_run` (default 500); a dense
  window is delivered split by split and the cursor keeps the split position, so the budget, a deadline or a rate
  limit resume from the last delivered split instead of the start of the window.
- **`unlink()` takes the sync lock and is one transaction** (audit FP-L5): a running sync refuses it
  (`SyncBusyException`; the page says *Another sync is running*); loaded sync rows can no longer leave a half-done
  unlink (connection gone, directory not pruned, no `BOARD_CONNECTION_UNLINKED`).
- **Only mapped people's time reaches the sink** (audit FP-I2); the others are counted in
  `SyncReport::$time->skippedUnmapped`. FP-I1 / I3 / I4 / I5 / I6 are documented in [SECURITY](SECURITY.md).
- **The erase cursor is signed** (audit FP-L3). `EraseCursor` gains an optional `signature` (round-tripped by
  `toArray()` / `fromArray()`): an HMAC-SHA256 (label `nowo_task_board.erase_cursor.v1`, keyed by `%kernel.secret%`
  through the new optional `TaskBoardEraser` argument `$cursorSecret`) bound to the board id, the actor id and the
  operation (empty or delete) and covering the phase, both counts and the attachment refs. On resume the eraser checks
  it with `hash_equals()` and throws the new `Exception\InvalidEraseCursorException` (an `InvalidArgumentException`)
  before touching anything when the cursor is unsigned, altered, or belongs to another board, actor or operation. A
  host that round-tripped the cursor through a browser let the user inject storage refs into `TASK_ERASED`, skip the
  unlink and `TASK_ERASED` with `phase=board`, loop `emptyBoard()` forever with `phase=columns`, or forge the counts of
  `BoardErasedEvent`. Also: refs are kept only for task ids still on the board, `emptyBoard()` resumes a column phase as
  the board phase, and a phase past the tasks is refused while the board still has tasks or pending refs.
- **Erase cursor run id, expiry and derived key** (audits FP-I9, FP-I10). The cursor also carries (signed) a random
  `runId` created by the first call and kept by every slice, its `issuedAt` (the eraser's clock) and an `anchorTaskId`
  (a task still on the board when it was issued). `TaskBoardEraser` gains the optional `$cursorTtlSeconds` (default
  3600): an older cursor is refused (`InvalidEraseCursorException::expired()`). A cursor of a finished run is refused
  (`finished()`): no unfinished cursor is returned for an empty board and the anchor of a finished run is gone, so a
  replay can no longer add its old counts to a new run's `BoardErasedEvent` (which gains an optional `runId`). The HMAC
  key is now a subkey `hash_hmac('sha256', 'nowo_task_board.erase_cursor', $secret, true)`, not the raw
  `kernel.secret`.

## [1.24.0] - 2026-11-05

Beacon's v1.122.0 audit (KIT-67, KIT-74, DATA-TASK-TOKEN-ERASURE): hosts no longer need request listeners or template copies for the board
index, the create check, an invalid create, the default view or the import help anchor. New API, backwards
compatible. See [UPGRADING](UPGRADING.md#to-1240).

### Added

- **Tenant on the create check:** `Security\TenantAwareTaskBoardAccessCheckerInterface` with
  `canCreateBoard(object $user, ?string $tenantKey = null)`; the index and the create action pass the tenant of the
  request. The bundle's checkers implement it and ignore the tenant; a 1.x checker is asked without it.
- **Default view per user:** `board.default_view_resolver` (`View\TaskBoardDefaultViewResolverInterface`, default
  `NullTaskBoardDefaultViewResolver`): the plain board route redirects to the list or Gantt route, except with
  `?view=board`, XHR or a same-origin `Referer` from the same board. The Board links of the index, list and Gantt pages
  carry `?view=board`.
- **Board index as a list page:** header actions with *New board* (only for whoever may create a board here), one
  panel with the boards or an empty state, a **New board** dialog and a `<noscript>` copy of the form; blocks
  `index_actions`, `index_board_item`, `index_empty`; partials `manage/_board_list.html.twig`,
  `manage/_board_list_item.html.twig` (Board / List / Gantt with icons and `aria-label`) and
  `manage/_board_create_form.html.twig`. New template variables `canCreateBoard`, `boardFormFallback`,
  `openCreateBoard`; `?new=1` opens the dialog.
- `board.create_dialog_header`: an invalid create from a host dialog answers `422` with only the create form.
- **Import help anchor:** `help_link.anchor_from: source` (also on the `nowo_task_board_import_help_link` global) — the
  link's `#anchor` follows the selected import source (`taskboard_json` → `#taskboard-json`), rendered server-side and
  kept up to date by the `nowo-task-board-select-anchor` Stimulus controller.
- `nowo-task-board-dialog`: a dialog with `data-open-on-connect` opens when it connects.
- Strings `task_board.index.new_board`, `.empty_hint`, `.views_of`, `.open_board`, `.open_list`, `.open_gantt` and
  `task_board.flash.board_invalid` in the 7 locales.

### Changed

- **Invalid New board:** the create action answers `422` with the index page, the dialog open and the submitted values
  and errors (plus an error flash), instead of a redirect to the index that lost them. A POST without the form still
  redirects to the index. `TaskBoardManageController::createBoard()` now returns `Response`.
- The index no longer shows the create form to users the create check refuses.

### Security

- **A sync never runs as a disabled or erased user** (Beacon audit DATA-TASK-TOKEN-ERASURE): the default
  `ConnectionCreatorActorResolver` refuses (null, the `no_actor` path) a user whose `isEnabled()` answers false, or that
  the new `sync.actor_checker` (a Symfony `UserCheckerInterface` service id, run with `checkPreAuth()`) refuses; any
  error of the checks refuses too. A refused named user is not replaced by the user who linked the board. Scheduled,
  queued and console runs (`nowo:task-board:sync` exits with an error) stop instead of acting with that person's token.
  A host `sync.actor_resolver` still replaces the default resolver. A named actor id that no longer resolves (deleted
  or erased) or whose lookup fails is refused as well: the 1.23 fallback to the linker is gone for named actors
  (audit EX-L2). `docs/sync.md` shows a Messenger handler resolving the actor and stopping on `null` (EX-L3).
- **Default-view redirect** (audit EX-L1): the query is appended to the generated list / Gantt path without route
  variables (`boardId`, `tenantSlug`…) or `_`-prefixed keys (`_fragment`, `_locale`…), so it cannot retarget the
  route; a route the router cannot build keeps the board view instead of answering 500.
- **422 answers** of an invalid create are `no-store, private`, with `Vary` on `board.create_dialog_header` in both
  modes (EX-I1).
- **Single-board tenancy** creates the tenant's first board only for a user `canCreateBoard()` accepts (EX-I2).

## [1.23.2] - 2026-11-05

Patch release — data-loss fix, **schema change** (see [UPGRADING](UPGRADING.md#to-1232)).

### Fixed

- **Deleting a task deleted its tracked time** (since 1.15): `Task::$timeEntries` was mapped with
  `cascade: ['persist', 'remove']` and `orphanRemoval: true`, and `task_board_task_time_entries.task_id` was
  `NOT NULL … ON DELETE CASCADE`, so removing a task (directly, through its board, its parent task or a bulk delete)
  removed every manual `TaskTimeEntry` logged on it. Deleting a task now never deletes tracked time:
  - `TaskTimeEntry.task` is nullable with `ON DELETE SET NULL`; `Task::$timeEntries` keeps `persist` only (no
    `remove` cascade, no orphan removal). Before a task is removed through the ORM (`Task` `#[PreRemove]`, also on the
    board's `remove` cascade) its entries are detached with fresh snapshots; deletes that bypass the ORM (DQL / SQL,
    the database cascades of `parent_id` and `board_id`) keep the entries through `ON DELETE SET NULL`.
  - New nullable snapshot columns on `task_board_task_time_entries`: `task_title_snapshot`, `board_id_snapshot`,
    `task_external_id` — filled when the entry is created, refreshed when the task is renamed (`Task::setTitle()`,
    so the edit form, imports and provider syncs) and right before the task is removed. Read a detached entry with
    `TaskTimeEntry::getTaskTitle()` / `getBoardId()` / `getTaskExternalId()` (live values while the task exists,
    snapshots after); `isDetached()` tells them apart. `TaskTimeEntry::getTask()` now returns `?Task`.
  - Removing one entry from the task detail still deletes it, now explicitly:
    `TaskTimeEntryRepositoryInterface::remove()` (new method) called by `TaskTimeLogger::remove()`, which takes the
    repository as a third constructor argument (autowired).
  - Provider syncs never deleted tasks (a task gone remotely is archived, unlinking removes only the sync state);
    tests now pin that the time stays through archive, rename and unlink.

## [1.23.1] - 2026-11-04

### Fixed

- **Native dialogs on iOS Safari** (found by symfony-beacon on iPhone): `.nowo-task-dialog--native` adds
  `max-height: calc(100dvh - 2rem)` after its `100vh` fallback, so the dialog fits the visible viewport.

## [1.23.0] - 2026-11-04

Provider connections and sync (ClickUp first). See [sync.md](sync.md) and [UPGRADING](UPGRADING.md#to-1230) —
**schema change**.

### Added

- **Provider sync (opt-in, `sync.enabled`):** link a board to a remote list and keep it up to date — an idempotent
  upsert keyed by the same (import scope, external id) the file imports use, per-field conflict detection (the local
  value wins and one note lists the provider's values), remote deletions archived and restored behind a mass-archive
  guard, comments, attachments and inline images copied through the host's storage, resumable runs (time slices, rate
  limits) with a run history, `nowo:task-board:sync <boardId> [--full] [--dry-run]`.
- `TaskProviderInterface` and the ClickUp API v2 adapter (`ClickUpTaskProvider`: fixed base URI, no redirects, 429 /
  `Retry-After` back-off, response caps), `ProviderCredential`, `TaskBoardSyncService`, `SyncReport`, `SyncCursor`, the
  host hooks (`ProviderCredentialResolverInterface`, `TaskAttachmentStorageInterface`, `InlineImageRewriterInterface`,
  `SyncLimitCheckerInterface`, `SyncRunnerInterface`, `SyncActorResolverInterface`) and the events `SYNC_STARTED`,
  `SYNC_PAGE_DONE`, `SYNC_FINISHED`, `SYNC_FAILED`, `TASK_REMOTE_ARCHIVED`, `TASK_REMOTE_RESTORED`,
  `BOARD_CONNECTION_LINKED`, `BOARD_CONNECTION_UNLINKED`.
- `RemoteFileFetcher` (SSRF-safe downloads) and `RemoteContentSanitizer` (Markdown without raw HTML or unsafe links).
- The connection page, the container browser (disclosure pattern + radio group, works without script), the progress
  display (polite live region) and the header badge; strings in the 7 locales.
- `Sync\Testing\InMemoryTaskProvider`, a scriptable provider for tests and demos; the demo uses it and its Playwright
  suite checks the flow with axe.
- **Archived tasks (every host):** `Task::archive()` / `restore()` / `isArchived()`, hidden from the board, list, Gantt and
  counts; an "Archived tasks" section with *Restore* in the list view (route `task_restore`).
- `TaskBoardResourceAccessChecker::canManageBoardConnection()` and `BoardAccessCheckEvent::getAction()`
  (`ACTION_VIEW`, `ACTION_MANAGE_CONNECTION`).
- `ImportedTaskDto::$assigneeEmails` (every assignee e-mail), `BoardAwareTaskImportUserResolverInterface`,
  `ImportedTaskUpdater::apply(…, ?Closure $mayWrite)`, `ArchiveAwareTaskRepositoryInterface`, `TaskLinkType::Attachment`,
  `TaskChangeType::SyncConflict` / `Archived` / `Restored`, `Import\ClickUp\ClickUpTaskMapper` (shared by the ClickUp
  JSON import and the sync).
- **Task member pictures:** `Avatar\TaskMemberAvatarResolverInterface` (`urlFor()` / `urlsFor()`, keyed by
  `TaskMemberKey::of()`, size 32; default `NullTaskMemberAvatarResolver` by alias), resolved once per page after the
  access checks (board, list: the 3 chips each card shows; task page / panel: members, comment authors, history users).
  The overridable partial `manage/_task_member_avatar.html.twig` and `nowo_task_board_member_avatar_url()` draw the chips
  of cards, the list, the task members, the members dialog, comments and history (classes unchanged). URLs must be
  `https://` without user info or a same-origin path (`AvatarUrlPolicy`); a broken picture falls back to the initial
  through one capturing `error` listener in `task-board.js` (no inline handler). Without a resolver the markup is
  unchanged.
- `scripts/record-clickup-fixtures.php` / `make record-clickup-fixtures`: records the ClickUp API fixtures, redacted.

### Changed

- `symfony/http-client` is now required. `symfony/html-sanitizer`, `nowo-tech/outbound-url-guard-bundle` and
  `symfony/mercure` are suggested.
- `findByBoard()`, `findByBoardWithin()`, `countByBoard()` and the TimeTrack task list leave archived tasks out.
- The test fixtures moved from `tests/Fixtures/clickup` to `tests/Fixtures/ClickUp`.

### Fixed

- Task tags keep a WCAG AA text contrast on Tabler: the framework's `text-purple` utility (4:1 on the soft
  background) no longer overrides the bundle's tag colour (found by the new demo axe run). The new connection badge
  gets the same treatment (`--nowo-task-sync-badge-color`).
- A sync run's lock is shown as free as soon as the run ends, also on a connection already loaded in the request.

### Known unconfirmed ClickUp behaviour

The ClickUp adapter was built from the API v2 documentation; its request shapes are unconfirmed against the live API
(owner decision 2026-11-04) and validated by Beacon after deploy. The adapter is defensive on each point:

- **Order of the task pages** (`order_by=updated&reverse=true`, meant to list the oldest update first): the run does
  not depend on it — the watermark is the newest date of all pages read, and the next run's overlap window re-reads the
  edge.
- **Attachments in the list answer and their fields:** only `id` and `url` are required; `title` / `name`, `size`,
  `mimetype` and `date` are read when present, extra fields are ignored, and a task without an `attachments` list has
  no files synced (no extra call).
- **`last_page`:** used when the answer has it; otherwise paging goes on until an empty page (a short page is not
  trusted to be the last), always within the run's page caps (`max_pages_per_run`).
- **Comment paging:** the documented `start` / `start_id` of the oldest comment; it stops on a page shorter than 25,
  an empty page, a cursor that does not move, or the comment caps.

Unexpected answer shapes are logged at debug level with their keys only, never a value. The recording script
(`make record-clickup-fixtures`) stays for a later confirmation.

### Security (1.23 audit, Appendix EG)

- **EG-L1:** the run lock is no longer re-entrant: a second request into a running run (a slice of the same run too)
  gets Busy and writes nothing.
- **EG-L2:** a slice reads at most `ceil(max_tasks_per_run / 100) + 1` task pages, and a run at most
  `sync.max_pages_per_run` (new, default 1000) over all its slices; past either cap the run fails with
  `response_too_large` and its cursor is cleared. No cap is derived from the list's task count (EG-L7: ClickUp's
  `task_count` may leave out the subtasks and closed tasks the pages hold).
  The task counter carries over the slices. A page that brings no new task ends the list (`paging_stopped`; the
  watermark is then not moved, EG-I11), and ClickUp comments are paged by raw rows, at most
  `ceil(max_comments_per_task / 25) + 1` pages, stopping when the cursor does not move.
- **EG-L3:** `RemoteContentSanitizer` keeps only fenced code blocks that start at column 0 as code (CommonMark finds
  exactly these); everything else, inline code spans included (their extent depends on the block structure), is
  escaped instead of deleted: every `<`, the `(` of an unsafe or empty link or image target whatever its label looks
  like, and the `:` of every `]:` with an unsafe target (also in a list item, a quote or after a code-span label); it
  sanitizes again until the text no longer changes. A `<` inside an inline code span therefore shows a backslash. Tested by
  rendering with league/commonmark (raw HTML and unsafe links allowed): the auditor's 40 cases and a differential
  fuzz (3 000 inputs in the suite; 3 × 100 000 locally) find nothing.
- **EG-L4:** the bundle's HTTP clients are not autoconfigured, so they never get the application logger (which logs
  URLs with their query); the private-network client keeps an explicit `kernel.reset` tag.
- **EG-L5:** a GET of the connection page reads the provider's top level only (cached); the opened levels of the
  browser without script are rendered by its CSRF-protected POST, never by a redirect to a GET.
- **EG-L6:** a row resolves at most 50 assignees (keyed, each e-mail once), and unresolved assignees are listed up to
  10 then counted (file import and sync).
- **EG-I1:** the avatar URL policy refuses invalid UTF-8, Unicode separators and control / format characters, and a
  port with a leading zero (PHP and JS, shared table).
- **EG-I3:** a replaced or removed attachment leaves the host's storage after the page is flushed.
- **EG-I8:** provider responses are weighed with the import JSON budget before `json_decode()`.
- **EG-I9:** attachment host patterns must name a host of two labels at least (`*.com` is refused).
- **EG-I10:** the host's credential page link is shown only for an `https://` URL or a same-origin path.
- Documented in SECURITY: serving stored files (EG-I2), the heartbeat window (EG-I4), the actor's roles (EG-I5), the
  outbound guard defaults (EG-I6) and the progress poll (EG-I7).

## [1.22.2] - 2026-11-04

### Fixed

- **Imports keep a flat memory (audit EB-L7).** The import orchestrator writes its tasks in batches of 200 (flush,
  then they leave the entity manager) inside one database transaction, so a failure anywhere still keeps nothing. At a
  128 MB `memory_limit` 5 000 CSV rows, 5 000 Trello cards (an export without actions) and 5 000 tasks of the
  bundle's JSON now import, and a ClickUp API export of about 2 700 tasks. 1.22.1 could not import 5 000 rows at
  128 MB from any source (about 26 KB per task; its "about 40 KB per task" was measured with query logging on).
- **`import.max_json_nodes` default 1 800 000 → 4 000 000**, calibrated through the import controller on a
  production kernel: every hostile JSON shape the audits tried stays under +70 MB at 128 MB. A 5 000-task ClickUp API
  export needs `max_json_nodes: 7500000` and 256 MB, a 5 000-card Trello export with its actions
  `max_json_nodes: 4500000` (see UPGRADING); hosts with a request baseline above about 55 MB should lower it.

## [1.22.1] - 2026-11-04

Import limits: CSV/JSON caps hardened, memory-aware JSON budget

### Security

- **Bounded imports (audits DL-L6, EB).** A wide CSV, a single line of 20 MB of commas, a grid of empty cells or a
  JSON file with millions of items could exhaust the memory of the request before the 1.22 row cap was checked. Every
  limit is now checked before anything is built:
  - CSV: one pass over the raw bytes (records, quotes and leading blanks found exactly as `fgetcsv()` finds them: a
    lone `\r` is part of a cell, `\v` / `\f` before a quote open it; audit EB-L4) refuses a header or a row with more than
    `import.max_columns` (default 512) columns, more than `import.max_cells` (default 1 000 000) cells — columns ×
    rows, empty cells included —, more than `import.max_rows` rows (blank lines count) and a cell longer than
    `import.max_cell_length`, before `fgetcsv()` splits a record; each record `fgetcsv()` builds is checked again (columns, cell length)
    before it is kept;
  - JSON (ClickUp, Trello, the own format): the text is weighed before `json_decode()` against
    `import.max_json_nodes` (default 1 800 000: strings by their length, list and object tables counted at their doubled size, as PHP grows them; about 16 bytes of decoded memory per unit;
    audits EB-L5, EB-I7, EB-L6), decoded with a depth
    limit of 64, and the items (nested subtasks of the own format included) are counted against `import.max_rows` before they are
    mapped to tasks;
  - `nowo:task-board:import` refuses a file over the upload form's 20 MB before reading it.

  Measured end to end at a 128 MB `memory_limit`, every hostile JSON shape is refused or imported cleanly, and about
  1 200 realistic tasks import from any source (the import costs about 40 KB per task). See UPGRADING for the
  settings of 5 000-task imports (`memory_limit` 256 MB for CSV, 512 MB and `max_json_nodes: 7500000` for JSON).

  The audit's memory PoCs (files just under 20 MB) are regression tests that run with `memory_limit=128M`.
- **Import help link:** a `help_link` URL with white space, a control character or a backslash renders `#` (browsers
  drop tabs and line breaks, so `/\t/evil.test` would become `//evil.test`).

### Added

- **Host help under the import source select:** an empty `import_help` block on the board import page and the
  Import subtasks dialog, and an optional escaped link `{url, label}` in both (the `help_link` include parameter or the
  Twig global `nowo_task_board_import_help_link`), so a host adds "Format and examples" without copying the templates.
  See [USAGE](USAGE.md#import-tasks-from-clickup-jira-or-trello).

### Changed

- Import limit errors (`max_rows`, `max_cell_length`, `max_columns`, `max_cells`, `max_json_nodes`) are now shown in the user's language:
  new keys `task_board.import.error.*` in the 7 locales. The English text and the console output are unchanged.
  `TaskImportResult` gained `errorTranslations` (the translation of an error, by its index in `errors`), and the
  parser throws `Nowo\TaskBoardBundle\Exception\ImportLimitExceededException` (an `InvalidArgumentException`).
- JSON import errors read `Invalid ClickUp JSON: <reason>`, `Invalid Trello JSON: <reason>` and
  `The file is not valid JSON: <reason>`; a valid document of the wrong kind (a number, a string) says what is
  expected.

## [1.22.0] - 2026-11-03

Idempotent import from every source

Idempotent task import from every source. See [UPGRADING](UPGRADING.md#unreleased) — **schema change**.

### Added

- Every import (ClickUp CSV / JSON, Jira CSV, Trello JSON, the bundle's CSV / JSON) is an idempotent upsert: rows are
  matched on (import scope, source id) stored on the task link (`task_board_task_links.import_source`, new, with index
  `idx_task_board_task_links_import`) and update their task; the same file twice is a no-op. The source wins for every
  field it has a value for, empty fields keep the application's value (`ImportedTaskUpdater`).
- Rows without an id are matched by a deterministic fingerprint (scope, parent, normalized title, creation date), with
  a warning; a fingerprint already imported into another board of the tenant is skipped like an id.
- A new task without a start date starts on the source's creation date (ClickUp `Date Created`, Jira `Created`); on
  create only.
- Cross-board rule: a source id already imported into another board of the tenant is skipped with a warning
  (`TaskImportLinkLookupInterface`, `DoctrineTaskImportLinkLookup`).
- `TaskImportResult::$updated` / `$unchanged` (+ withers); the flash messages, the command and the import log report
  created / updated / unchanged / skipped.
- ClickUp CSV: `Start Date` (Gantt), `Date Created`, `Dependencies` / `Waiting On` / `Blocking`; ClickUp JSON:
  `start_date`, `date_created`, `dependencies`; Jira: start date, `Created`, "Blocks" issue links; Trello: `start`.
  Dependencies resolve within the file and against tasks imported before.
- Assignees given by name (ClickUp, Jira, Trello members, own format) resolve through `assignee_provider` when exactly
  one assignable user has that display name (`ImportAssigneeResolver`); otherwise one warning per name.
- `ImportFieldMapper::parseDate()` (millisecond / second timestamps, ISO, day-first and month-first numeric dates, Jira
  and ClickUp text dates), `parseNames()`, `parseIdList()`, `mapDependencies()`; `Task::setParent()`;
  `TaskImportSource::provider()`, `scope()`, `hasGlobalIds()`, `fromLegacyLabel()`.

### Changed

- Only links an import wrote (`import_source`) are matched; links from before 1.22 are not (one-off backfill SQL in
  UPGRADING), and the link form can no longer change the external id of an import link (audit DL-L2).
- A re-import moves a task under its source parent row when that changes (never under its own descendant); a row
  without a parent keeps its task where it is.
- An existing dependency met again on re-import is silently kept (it was a "duplicate or cycle" warning); a refused
  one says why.
- Dates without a time are midnight instead of the import time; 10-digit numbers are seconds timestamps.
- CSV parsing drops a UTF-8 BOM (the first header was lost), keeps quoted multi-line cells, and joins repeated headers.
- Jira `Time Spent` is no longer read as an estimate. The import form's help and the source descriptions mention the
  new columns (7 locales).
- The import form no longer renders the **Skip tasks that were already imported** checkbox.

### Security (1.22 audit, Appendix DL)

- An import only updates, moves, re-assigns or links tasks the importer may edit (`canEditTask`, so
  `TASK_ACCESS_CHECK` / `markReadOnly()` apply); other matched rows are skipped and counted, never echoed (DL-M2).
- **Import subtasks** matches only the tasks earlier imports put under that same task (`<provider>@<task id>` for every
  source): the rights on one task no longer reach the rest of the board (DL-M1).
- Cross-board and read-only warnings give counts only; an unknown and a non-assignable e-mail read the same, and
  e-mails respect `assignee_provider::canBeAssigned()` (DL-L1, DL-L3).
- `import.max_rows` (5000) and `import.max_cell_length` (65536), linear de-duplication, iterative parent ordering, an
  exact ancestor check on moves, and dates bounded to 1970-2200 without relative input (DL-L4, DL-L5, DL-I2).

### Deprecated

- `TaskImportOptions::$skipExisting`, `TaskImportFormData::$skipExisting` and `nowo:task-board:import --force`: ignored
  since every import is an upsert.

## [1.21.0] - 2026-11-03

Bounded board pages, injected clock and logger, repository hygiene. See [UPGRADING](UPGRADING.md#to-1210).

### Added

- `board.max_tasks` (default **500**, REQ-PERF-001): the board, list, Gantt and task pages load at most that many tasks
  per board (the first cards of every column) with their members and users in one extra query, and say "Showing N of
  M tasks" (`manage/_task_window_notice.html.twig`, Twig variable `taskWindow`) when a board has more.
- `Repository\BoundedTaskRepositoryInterface` (implemented by the Doctrine task repository): `findByBoardWithin()`,
  `countByBoard()` and `findForDetail()` — the task page and panel fetch the task's relations in a fixed number of
  queries instead of one query per member / history entry.
- REQ-OBS-001: the manage controller logs every successful write (`info`, action + object ids) and every refused CSRF
  token (`warning`); `TaskImportOrchestrator` logs the start, result and failure of every import. No user data, text,
  file names or tokens are logged (docs/CONFIGURATION.md, Logging).
- REQ-DI-001: `TaskGanttBuilder` and the manage controller take a `Psr\Clock\ClockInterface` (default `NativeClock`).
- Demo: a PHPUnit kernel test (routes imported, bundle templates compile); `release-check` runs `update-bundle`, the
  demo tests and the healthcheck (REQ-DEMO-007).
- Root `make assets` (pnpm build) and `make test-ts` prints the global TS % (REQ-MAKE-001, REQ-TEST-009);
  `validate-translations` checks the key parity of the 7 locales (REQ-MAKE-004).
- `.cursor/` versioned: rules pack, `mcp.json` (Engram) and the Spec Kit skills (REQ-IDE-001/003/004, REQ-DOCS-008,
  REQ-GIT-001, REQ-SPECKIT-001).

### Fixed

- Adding a member crashed (500) when the host user class keeps `email` / `username` in a private property:
  `TaskMemberAssigner` now reads the getter (or a public property) for the history label.
- A submission without (or with an empty) board name, column name, column set name, task title, link URL, task
  priority or link type was a 500 (TypeError on the form DTO); it is now a validation error, or the default
  priority / link type.
- The import form's file type check needs `symfony/mime`, which is now a requirement (it was only installed
  transitively).

### Tests

- REQ-TEST-003: no more coverage exclusions for `src/Controller`, `src/Repository`, `src/Doctrine` and `src/Form`
  (100%, 4831/4831). New `functional` suite: `FunctionalKernel` (SQLite in memory, or `TASK_BOARD_TEST_DATABASE_URL`)
  renders every manage page, drives the writes over HTTP and runs every Doctrine repository query; the CI coverage
  job enforces the 100% gate and a `mysql` job runs the functional suite on MySQL 8.4.

### Changed

- Composer: `psr/clock`, `psr/log` and `symfony/clock` are explicit requirements (already installed through
  FrameworkBundle); `composer test` runs with `memory_limit=512M`.
- The demo web port is published on `127.0.0.1` only.
- `ImportFieldMapper` builds dates from Unix timestamps without reading the current time.
- Spec Kit baseline: inventory of all 262 production files of `src/` and the requirements of releases 1.10–1.21.
- docs: DEMO-FRANKENPHP (dev / prod start, troubleshooting), the REQ-SF-004 route loader exemption, query budget and
  logging (CONFIGURATION), logging and personal data (SECURITY), README coverage figures.

## [1.20.1] - 2026-10-30

Every manage form is a FormKit Type (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-1201).

### Changed

- **FormKit Types**: `TaskPriorityFormType`, `CsrfActionType`, `TaskTimeEntryFormType`, `TaskDependencyFormType`,
  `TaskCommentFormType` and `TaskListFilterType` carry `#[FormKitConfig('task_board')]` + `FormOptionsTrait` like the
  other manage forms, so every page form of the bundle is on the `nowo_form_kit.profiles.task_board` profile. Their
  fields keep their explicit options: same form names, field names, ids, classes and Stimulus attributes.
- **Docs**: CONFIGURATION documents the `task_board` FormKit profile and how a host overrides it.

## [1.20.0] - 2026-10-30

### Added

- **Previous / next task** on the task page (`manage/_task_navigation.html.twig`, block `nowo_task_navigation`): in the
  board order (columns left to right, each column's cards top to bottom, so the end of a column goes on to the next
  one; top-level cards only with `board.top_level_only`) with the position (*3/14 · In progress*); `?from=list` follows
  the list's order instead (the list's title links carry it) and every step keeps it. A subtask also steps through its
  parent's subtasks (*Subtask 2/5*, with a link back up to the parent); a task with subtasks offers *First subtask*.
  The ends render as disabled steps. Alt+← / Alt+→ (outside a multi-line text) and K / J (outside any field) step; the
  `nowo-task-board-task-nav` controller asks before leaving unsaved edits of the task form.
- The task **panel** shows the subtask steps only, their links carrying `data-task-panel-url` (a drawer opens them in
  place; the host's drawer walks its own view). Subtask titles in the panel's tree carry it too.
- `Nowo\TaskBoardBundle\Support\TaskNavigator`, `Dto\TaskNavigation`, `Dto\TaskPosition`; view variable
  `taskNavigation` on the task page and panel.
- Board cards carry `data-title` (the task title alone, for a drawer's heading and accessible name) and
  `data-column-name`; list rows and Gantt bars too.
- The **estimate is a duration**: the field shows *24 h*, *1 h 30 min* and reads *90* (minutes, as before), *45 min*,
  *2 h*, *1.5h*, *1h30*… (`Form\DataTransformer\MinutesToDurationTransformer`); anything else is a form error. The
  model still holds minutes.

### Changed

- The task attributes are a **stacked list**, one field per row (People, Dates, Time tracking, Tags, Colour), the label
  in a narrow column: start and due date share one row (side by side, wrapping on a phone), the people chips are
  followed by their add button, tags and colours take the row's width.
- Beside the time spent, the remaining time is worded — *24 h left* (neutral) or *Over by 2 h* (danger colour) — with
  the signed deviation in the tooltip, instead of a green *−24 h (−100 %)*.
- Board cards show the estimate as a duration (*24 h*, not *1440m*).
- Subtasks: the title, its counter, a themed progress bar and the fold toggle (an icon button) sit together in the
  header; each row has the title on the left and the column and tags as badges in a right-hand column (the bundle's
  own `.nowo-task-badge` / `.nowo-task-tag` pills, sentence case, no host `badge` classes needed).
- Colour swatches stay round: the picked one gets a ring and a check (it no longer grows into a square).
- Section headings read alike: *Description* is sentence case like *Subtasks* and *Time tracking*.

## [1.19.1] - 2026-10-30

### Fixed

- Task page: the history card's activity filter, stream and comment box touched the card's edges; they keep its
  inner padding now.

## [1.19.0] - 2026-10-30

### Added

- **Host text renderer** `Nowo\TaskBoardBundle\Text\TaskTextRendererInterface` (`render(string $text): string`, safe
  HTML): comment bodies and plain-text descriptions go through it. The default `PlainTaskTextRenderer` keeps them plain
  (escaped, line breaks); alias the interface to render Markdown or your own markup. @mention chips survive any renderer.
- A plain-text description (no rich editor) is **shown rendered** on the task page and panel; editors open the field
  under it with *Edit description* (readers only see the text). Twig function `nowo_task_board_text(text)`.

### Changed

- `TaskBoardTwigExtension::commentBody()` takes an optional renderer (default plain: same output as before).

## [1.18.1] - 2026-10-30

### Changed

- Compact task attributes (task page and panel): rows at the height of their fields (no 2.75rem minimum), compact
  inputs and selects, one line above and one below the grid (the last row draws none of its own), less padding around
  it and between the detail sections.

## [1.18.0] - 2026-10-30

### Added

- **Import subtasks into a task** (route `task_import`, POST, editors only): the task page and panel offer *Import
  subtasks*; the file's rows without a parent become subtasks of the task, the others hang from their parent row, and
  their Gantt relations are created. `TaskImportOrchestrator::import()` takes an optional `parentTask`.
- **The bundle's own import format**, `taskboard_csv` / `taskboard_json` ([docs/import/taskboard.md](import/taskboard.md)):
  `id`, `parent` (or nested `subtasks` in JSON), `title`, `description`, `status`, `priority`, `assignee`, `start`,
  `due`, `estimate`, `tags` and `depends_on` (Gantt relations, `ID` or `ID:type`). Relations to rows outside the file,
  duplicates and cycles are skipped with a warning; `TaskImportResult::$dependenciesCreated` counts the rest.
  `ImportedTaskDto` gains `startAt` and `dependsOn`.
- **Import help modal** (`manage/_import_help.html.twig`) on the import page and in *Import subtasks*: what each format
  imports (ClickUp, Jira, Trello, the own format), the own format's columns and examples (7 locales).

## [1.17.1] - 2026-10-30

### Fixed

- 1.17.0 bound the two new template arguments (`$filtersCollapse`, `$filtersFormClass`) to the route loader instead of
  the Twig extension: the container did not compile ("has no argument named $filtersCollapse"). An integration test now
  resolves the named arguments of every bundle service.

## [1.17.0] - 2026-10-30

### Added

- `templates.filters_collapse` (Twig global `nowo_task_board_filters_collapse`): a host partial that folds the list
  view filters, e.g. UiKit's `partials/_filters_collapse.html.twig` (corner tab on the right, "N active filters" while
  folded) or the host's wrapper of it, so the list folds like the host's other filter cards. It is included as the
  first child of the filter form with `key` / `fold_key` (`nowo-task-board-list-<board id>`) and `form` /
  `filter_form`. Null (default) keeps the bundle's own "Filters" toggle.
- `templates.filters_form_class` (Twig global `nowo_task_board_filters_form_class`): extra classes on the list view
  filter form (the host's filter card, e.g. `issue-filters panel`).

## [1.16.1] - 2026-10-29

Fixes from a host UX audit (phone layouts, contrast, tap targets). No schema change, no API change.

### Fixed

- **Gantt**: the grid is as wide as its timeline (`width: max-content`), so the sticky title column stays in view once
  the chart opens on today (it only measured the visible width and scrolled away). A completed task's title cell
  loses its `opacity: 0.75` — the bars no longer show through the sticky cell; the title is already dimmed.
- **List filters**: the label rules (`.nowo-task-board-list-filters label`, the checkbox label `display: inline`) are
  wrapped in `:where()` (no specificity), so a host form theme's checkbox label keeps its box beside its text.
- **Task detail**: the attributes stack (label above value) when the attribute grid is narrower than 30rem — a
  container query (`container: nowo-task-attrs`), so a drawer on a phone no longer lets the estimate input overflow.
  A deviation duration ("0 min") stays on one line.
- **List groups**: the column name reads in the text colour on a lightly tinted chip with a column-colour dot
  (WCAG AA whatever the column colour; light colours as text fell to 3.3:1).
- **Tap targets on phones** (≤ 640px): column edit button, a tag's remove button in the task detail and the dialog
  close button are at least 32×32px; dialog footer buttons are at least 32px high everywhere.
- **People dialog**: the person picker shows the person's name with the e-mail after it ("Ada Lovelace
  (ada@example.com)", `UserLabel::option()`; the `user_choice_label` option now defaults to `null` = that label, a
  property path or callable still overrides it), and Close / Add member share the dialog footer like the other
  dialogs (the submit button names the add form with `form="nowo-task-members-add-<task id>"`).

## [1.16.0] - 2026-10-29

Task comments with @mentions (ClickUp-like activity), and titles / labels cut to their column at the service level.
**Schema change**: one new table (see UPGRADING).

### Added

- **Comments** (`TaskComment`, table `task_board_task_comments`: task, author, plain-text body with line breaks,
  created / edited / deleted dates, the mentions): a comment box closes the activity stream of the task detail and
  panel; comments are interleaved with the history in time order, with a **All activity / Comments** toggle.
  Authors edit their comments in place; the author or an editor of the task deletes one (soft delete: "Comment
  deleted" keeps its place). Routes `task_comment`, `task_comment_edit`, `task_comment_delete` (CSRF, length 5000,
  `TASK_ACCESS_CHECK`); the authors are fetched with the comments (`TaskCommentRepositoryInterface::findByTask()`).
- **Readers may comment**: `nowo_task_board.comments.readers_can_comment` (default **true**, like ClickUp guests with
  comment rights): someone who may read a task but not edit it (a read-only board) still comments; `false` keeps
  comments to editors.
- **@mentions**: typing `@` in a comment lists the people who can read the task (↑ ↓, Enter / Tab, Escape;
  `role="combobox"` with `aria-activedescendant`, the `nowo-task-board-mention` controller). The mentions are stored
  structured (ids and names of valid candidates still named in the text; anything else stays plain text), shown as
  chips, and a comment that mentions the viewer is highlighted. Who can be mentioned comes from
  `TaskMentionCandidatesResolverInterface` (`comments.mention_resolver`; default: the board's assignable users, else
  its creator and task members).
- **Events** after flush: `TaskBoardEvents::TASK_COMMENT` (`TaskCommentEvent`, created / edited) and
  `TASK_MENTION` (`TaskMentionEvent`: the task, the comment and the people mentioned — on an edit only the ones it
  adds; never the author).
- **Counts**: the comment count on the board cards and in the list rows (`commentCounts`, one query per page).
- Tokens `--nowo-task-mention-bg`, `--nowo-task-mention-ink`, `--nowo-task-mention-me`; Twig function
  `nowo_task_board_comment_body(comment, viewerId)`.

### Fixed

- A label or a title longer than its column given by code (`TaskLinkAttacher::attachObject()`,
  `TaskLink::forHostObject()`, `new TaskLink()`, `new Task()` / `setTitle()`, board and column names) was a
  "Data too long" 500: the entities cut them to the column (`Support\Text::cut()`).

## [1.15.0] - 2026-10-29

Dependencies edited in the task detail, time tracking with the deviation, list time columns, sorting and folding
filters, Gantt scales and week start, a task start date. **Schema change**: one nullable column (see UPGRADING).

### Added

- **Dependencies**: *Add dependency* in the task detail and panel (a native dialog with the relation — blocks,
  blocked by, related to, duplicates — and a searchable list of the board's other tasks), both directions listed with
  a remove button. `TaskDependencyManager` refuses the task itself, another board, duplicates and cycles of "blocks"
  links with a flash message; the changes are recorded in the activity (`TaskChangeType::DependencyAdded` /
  `DependencyRemoved`). Routes `task_dependency`, `task_dependency_remove`; `TaskDependencyFormType`,
  `TaskDependencyType::relation()`, `Task::addOutgoingDependency()` / `removeDependency()`.
- **Time tracking and deviation**: a *Time tracking* section with the estimate, the time spent, the difference in time
  and % coloured on track / over / under (`Support\TaskDeviation`), the subtasks' sum beside the task's own, the
  manual time entries and *Log time* (minutes, day, note; `TaskTimeLogger`, `TaskTimeEntryFormType`, routes
  `task_time`, `task_time_remove`, `TaskChangeType::TimeLogged` / `TimeRemoved`). Twig filter
  `nowo_task_board_duration` ("1 h 30 min") and function `nowo_task_board_deviation(task)`.
- **List**: optional Estimate, Spent and Deviation columns (*Show time*, remembered per board) with the totals of the
  shown rows, and an *Over estimate* filter.
- **Gantt scales**: Hours, Days, Weeks and Months (`?scale=`, remembered per board), each between a minimum and a
  maximum number of columns — a longer plan is clamped to a window around now and the chart says so. The bars show the
  time spent against the estimate (fill and over-run marker) and the numbers in their tooltip. `GanttScale`,
  `TaskGanttSlot`, `TaskGanttTimeline::$slots` / `$scale` / `$weekStart` / `$clamped` / `$todayIndex`, more
  `TaskGanttItem` fields.
- **Week start**: `nowo_task_board.gantt.week_start` (monday | sunday) and `gantt.week_start_resolver`, a
  `WeekStartResolverInterface` service the host answers per request (e.g. a user preference).
- **Start date**: `Task::$startAt` (column `task_board_tasks.start_at`, nullable), edited next to the due date in the
  task detail and the *Add task* dialog (a start after the due date is a form error); the Gantt starts the bar there
  (`Task::getPlannedStart()`: the start date, else the creation), in every scale. Recorded as
  `TaskChangeType::StartDate`; `TaskUpdatedEvent` lists `startAt`.
- **Parent path**: a subtask's detail and panel open with its parents ("Epic › Story › this task"), Twig function
  `nowo_task_board_ancestors(task)`.
- **List sorting**: a *Sort* select (title, priority, due date, column; board order by default) orders the rows of
  every group.
- **Tall board columns** may scroll inside themselves: tokens `--nowo-task-column-max-height` and
  `--nowo-task-column-overflow` (defaults none / visible).
- Stimulus controllers `nowo-task-board-option-filter` and `nowo-task-board-filter-collapse`; tokens
  `--nowo-task-gantt-spent`, `--nowo-task-overrun`, `--nowo-task-on-track`, `--nowo-task-under`.

### Fixed

- A task title over 255 characters gave a 500 (MySQL "Data too long"): the title, the board name and slug, the column
  name and the link fields have a `Length` constraint and a `maxlength`; a refused inline task says why in a flash.
- The task detail listed only the outgoing dependencies: the incoming ones ("blocked by", "duplicated by") show too.

### Changed

- **List filters** fold behind a *Filters* toggle (disclosure chevron, `aria-expanded`, state per board in
  localStorage, open while a filter is active).
- **Collapsibles** of the task detail and panel (Documentation, Activity, subtask children) use the platform
  disclosure toggle (UiKit `_disclosure_icon` with its morph icon, `_disclosure.html.twig`) instead of the native
  marker.
- The task detail's time spent reads as a duration ("2 h 5 min") with the deviation beside it.
- `TaskGanttBuilder::build()` takes the scale, the week start and the current time (all optional; the days scale is
  the default and keeps the 1.13 range rules); `TaskGanttItem` indexes are columns of the scale.

## [1.14.1] - 2026-10-29

### Fixed

- **New board without a slug**: submitting the board form with the optional *Slug* empty gave a 500 (`null` into the
  string slug); the empty field now submits `''` and the slug is derived from the name, as documented.
- **Task detail accessibility**: the due date, estimate, tags and description fields (drawn without a visible label
  next to their row title) now carry an `aria-label`, so screen readers name them (axe rule *label*).

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

### Security (1.22 audit, Appendix DL)

- An import only updates, moves, re-assigns or links tasks the importer may edit (`canEditTask`, so
  `TASK_ACCESS_CHECK` / `markReadOnly()` apply); other matched rows are skipped and counted, never echoed (DL-M2).
- **Import subtasks** matches only the tasks earlier imports put under that same task (`<provider>@<task id>` for every
  source): the rights on one task no longer reach the rest of the board (DL-M1).
- Cross-board and read-only warnings give counts only; an unknown and a non-assignable e-mail read the same, and
  e-mails respect `assignee_provider::canBeAssigned()` (DL-L1, DL-L3).
- `import.max_rows` (5000) and `import.max_cell_length` (65536), linear de-duplication, iterative parent ordering, an
  exact ancestor check on moves, and dates bounded to 1970-2200 without relative input (DL-L4, DL-L5, DL-I2).

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

[Unreleased]: https://github.com/nowo-tech/TaskBoardBundle/compare/v2.2.0...HEAD
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
