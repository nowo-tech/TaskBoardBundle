# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[2.17.0] - 2026-11-14](#2170---2026-11-14)
- [[2.16.0] - 2026-11-13](#2160---2026-11-13)
- [[2.15.1] - 2026-11-13](#2151---2026-11-13)
- [[2.15.0] - 2026-11-13](#2150---2026-11-13)
- [[2.14.1] - 2026-11-12](#2141---2026-11-12)
- [[2.14.0] - 2026-11-12](#2140---2026-11-12)
- [[2.13.0] - 2026-11-12](#2130---2026-11-12)
- [[2.12.0] - 2026-11-12](#2120---2026-11-12)
- [[2.11.1] - 2026-11-12](#2111---2026-11-12)
- [[2.11.0] - 2026-11-10](#2110---2026-11-10)
- [[2.10.1] - 2026-11-10](#2101---2026-11-10)
- [[2.10.0] - 2026-11-10](#2100---2026-11-10)
- [[2.9.1] - 2026-11-09](#291---2026-11-09)
- [[2.9.0] - 2026-11-09](#290---2026-11-09)
- [[2.8.1] - 2026-11-09](#281---2026-11-09)
- [[2.8.0] - 2026-11-09](#280---2026-11-09)
- [[2.7.0] - 2026-11-09](#270---2026-11-09)
- [[2.6.0] - 2026-11-08](#260---2026-11-08)
- [[2.5.0] - 2026-11-08](#250---2026-11-08)
- [[2.4.0] - 2026-11-08](#240---2026-11-08)
- [[2.3.1] - 2026-11-08](#231---2026-11-08)
- [[2.3.0] - 2026-11-08](#230---2026-11-08)
- [[2.2.1] - 2026-11-08](#221---2026-11-08)
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

Target: next minor after the 2.18 stack (written 2.19.0 in the code; the number is settled at release). Additive; one
schema change (nullable columns), see [UPGRADING](UPGRADING.md#to-the-next-minor-after-218-member-badges-and-write-provenance).

### Added

- **Member badges (Beacon spec 234, D-20261114-05).** `member_badge_provider`: a framework-free port
  (`Member\MemberBadgeProviderInterface::badgesFor()` / `badgesForMany()`, `ValueObject\TaskBoardUserRef` in,
  `Member\MemberBadge` out) for a pill next to a member's name in the history, the comments and the assignee / watcher
  chips (Beacon: *Agent* for service accounts). Plain-text values with an allow-listed variant (`neutral`, `info`,
  `success`, `warning`, `agent`), printed escaped; asked once per task page and memoised per request only
  (`Member\MemberBadges`, reset at every boundary). The `member_label` macro takes `badges = true` where it shows them,
  and `manage/_member_label_suffix.html.twig` has a `member_label_suffix` block for presentation overrides. Without a
  provider nothing is asked and the output is byte-identical.
- **Write provenance (Beacon spec 234).** `write_provenance_provider`: a framework-free port
  (`Provenance\WriteProvenanceProviderInterface::current(): ?WriteProvenance`, `WriteChannel` `web|app|api|mcp`, actor
  label) asked at every task write that records history and every comment added or edited. Stored on history rows
  (`channel`, `actor_label`) and, as the last write, on tasks and comments (`updated_by_id`, `channel`, `actor_label`).
  The actor label is plain text, cut to 120 characters when stored (never refused), printed escaped. The history and
  comments show "via API · label"; the Task API answers of tasks and comments gain `created_by`, `updated_by`,
  `channel` and `token_name`. Without a provider the provenance columns stay `NULL`.
- The account erasure (`TaskSyncPeopleReassigner::detachUser()`) drops the person from `updated_by_id` and, with
  `$anonymise`, clears the actor labels of the rows they wrote.

### Changed

- `Task` and `TaskComment` keep their last writer (`updated_by_id`) from now on, with or without a provider. A change of
  `updatedBy`, `channel` or `actorLabel` alone is no new task revision.

## [2.17.0] - 2026-11-14

Target 2.17.0 (minor). No schema change, no migration, no configuration change.

### Fixed

- **Task revisions are incremented by the database (audit PC-L4, spec 234 FR-018).** Until 2.16.0 the revision was
  computed in PHP and written as a value, so a writer that does not lock the row (the board UI, `PUT /v2/task/{id}`
  without `If-Match` / `base_revision`, a sync, a recurrence) and loaded the task before a locked write committed saved
  the same number for different content, and a later `If-Match` passed on stale data. `Doctrine\TaskRevisionListener`
  now only marks the changed tasks in `onFlush`; inside the flush's own transaction, after the task's (or its member's)
  row is written, it runs `UPDATE … SET revision = revision + 1 WHERE id = ?` and puts the value written into the
  object (`Doctrine\TaskRevisionIncrement`: `RETURNING` on PostgreSQL / SQLite, `LAST_INSERT_ID(revision + 1)` and the
  connection's insert id on MySQL / MariaDB, so no read is added; UPDATE then SELECT on any other platform).
  The row stays locked by that transaction until the commit, so every write gets the next number after the last
  committed one; a rolled-back write takes its 1 with it. The column is mapped `updatable: false` (attributes and XML),
  so no flush writes a revision from PHP any more. What counts as a revision is unchanged: once per flush, never for a
  position, total time or `updatedAt` change alone, also for an assignee or watcher added or removed. Tested with two
  entity managers on two connections (one in-memory SQLite connection by default; MySQL 8.4 and PostgreSQL 16 in CI).
- **A task deleted during a write (audit PG-L1).** The increment answers "row gone" the same way on every platform
  (no row from `RETURNING`, 0 affected rows on MySQL / MariaDB and elsewhere): the flush succeeds as in 2.16 and the
  object keeps its revision, instead of a 500 (`NoIdentityValue`) on MySQL / MariaDB or revision 0 on PostgreSQL /
  SQLite. A mark a failed flush left on a task is dropped by its next flush (audit PG-I1).
- **Column deletion order (audit PG-L2).** `BoardColumnManager::delete()` asks the column transition policy and moves
  the tasks in the column's order (position, then id) instead of the board collection's undefined order: the refusal
  reported and the order of the moved cards no longer depend on the database (MySQL loaded them by primary key).

### Changed

- `Task::bumpRevision()` (`@internal`, called only by the bundle's listener) is replaced by the `@internal`
  `markRevisionPending()` / `takeRevisionPending()`. `TaskRevisionListener` (`@internal`) also listens to
  `postUpdate`, `postPersist` and `postRemove`.

## [2.16.0] - 2026-11-13

Target 2.16.0 (minor; Symfony Beacon spec 234 "AI Flow on Beacon tasks", steps 2–6). Additive: with an unchanged
configuration every existing answer and behaviour stays, except that the Task API answers gain `revision`,
`humans_only` and an `ETag`, and `/v2/team` lists members once `api.hooks.members` is set. **Schema change for every
host** (three columns, one optional index) and one more table with the opt-in task templates: see
[UPGRADING](UPGRADING.md#to-2160).

### Added

- **Column transition policy** (FR-014 – FR-017). `Transition\ColumnTransitionPolicyInterface::decide(ColumnTransition):
  ColumnTransitionDecision` (framework-free: board, task and column snapshots, the task's tags, the actor and a
  `ColumnTransitionVia` channel `web` / `api` / `mcp` / host label and operation `create` / `move` / `bulk` /
  `checklist`). Asked before anything changes by every move of `TaskManager` (`move()`, previous / next / done,
  `moveToColumn()`, a column change in `update()`, a create in a column), board UI included, and by every Task API
  operation that sets a status (create, update, bulk, checklist items) with the principal's own channel (an MCP
  call reads `mcp`, never `web`). A refusal carries a code and a message: the Task API answers 403 with that code
  (`STATUS_HUMANS_ONLY`, a host's `WIP_LIMIT`, `BLOCK_REASON_REQUIRED`, …; `STATUS_REFUSED` by default) and message;
  the board shows the message as a flash and the dragged card goes back (409 on the drag). Option
  `column_transition_policy` (default `AllowAllColumnTransitionPolicy`); the bundled opt-in
  `HumansOnlyColumnTransitionPolicy` refuses `api` and `mcp` into a *humans only* column, checklist items included
  (strict; `humans_only_policy.allow_checklist: true` lets them tick checklist items).
  `TaskManager` methods gain an optional trailing `?ColumnTransitionVia $via` (null = `web`) and
  `assertCanEnter()`; `ColumnTransitionRefusedException` (with the decision) is new.
- **`humansOnly` column flag** (`task_board_columns.humans_only`, default false): `BoardColumn::isHumansOnly()` /
  `setHumansOnly()`, `BoardColumnFormData::$humansOnly`, `BoardColumnManager::setHumansOnly()` (the domain setter a
  board configuration copier uses; COLUMN_UPDATED with field `humansOnly`), a toggle in the add / edit column dialogs
  (`board.column_humans_only_field`, shown by default only with a `column_transition_policy`), a tag in the column
  header, and `humans_only` on every API status (read, and written by `POST` / `PUT /v2/list/{id}/status…`).
- **Optimistic locking** (FR-018). `Task::getRevision()` (`task_board_tasks.revision`, 1 for a new task) is bumped once
  per flush that changes the task (its position, total time and `updatedAt` aside) or its assignees / watchers
  (`Doctrine\TaskRevisionListener`). The Task API returns `revision` on every task and `ETag: "n"` on
  `GET` / `PUT /v2/task/{id}`; `PUT /v2/task/{id}` honours `If-Match` (`"n"`, `n`, `W/"n"`, `*`) or `base_revision`: the
  row is locked, a stale value answers 409 `TASK_409` with `current_revision` and nothing is written.
- **Atomic bulk update** (FR-021): `POST /v2/task/bulk` with `{"tasks": [{"id", "base_revision"?, "description" |
  "markdown_content", "status", "tags", "priority", "due_date", "start_date", "assignees": {"add", "rem"}}]}`, at most
  50 tasks of one space (`BULK_003`, `BULK_002`), one transaction (rows locked in id order). Any refusal writes nothing
  and answers 409 `BULK_001` with every failing item in `failures` (index, id, its own status and ECODE, err,
  `current_revision`); a
  field a bulk item does not take is refused. After the commit, one `API_WRITE` per task (`task.bulk_update`) and one
  `TaskBoardEvents::API_BULK_WRITE` (`TaskApiBulkWriteEvent`, with `$count`), so a host counts N writes. Worker mode:
  refusals are checked before the first write; a failed write rolls back, resets a closed manager and reloads the
  touched tasks (`Api\Operation\TaskApiTransaction`). The one-space rule compares the tenant keys byte for byte
  (2.15.1, REQ-SEC-006): a look-alike tenant is another space.
- **Text search** (FR-026): `query` on `GET /v2/team/{id}/task` and `GET /v2/list/{id}/task`, through
  `Search\TaskTextSearchInterface` (option `search.provider`), default `DoctrineTaskTextSearch`: every word in the title
  or description (and, with `search.comments`, a comment that is not deleted); MySQL FULLTEXT with `search.fulltext`
  (index mapped on MySQL only, `search.min_token_length`), `LIKE` with escaping elsewhere and for short words; user text
  never reaches the server as boolean-mode operators. Matches are taken only within the boards the token reaches,
  before any page or limit, then every other filter and *My tasks* apply in SQL; more than `search.max_matches`
  (default 500) answers 400 `SEARCH_001`.
- **Team members** (FR-025): `Api\Hook\TaskApiMembersInterface` (`api.hooks.members`, default empty) with
  `Api\Value\TaskApiMember` (id, name, `isServiceAccount()`, e-mail, colour, picture). `/v2/team` lists them as
  ClickUp's `members[].user` plus `service_account`; the e-mail is kept only on the viewer's own row whatever the host
  returns. `GET /v2/team/{id}/member` (all) and `?name=` (prefix of the name or a word of it, case and accents aside;
  id, username and service-account flag only; at most 20). A team the viewer's workspace hook does not list is 404
  before the host is asked.
- **Comment edit**: `PUT /v2/comment/{id}` (`comment_text`, ClickUp's shape, answers `{}`), for a token that may comment
  on the comment's task, then `api.hooks.comment_edit_policy` (`TaskApiCommentEditPolicyInterface`, default
  `AuthorOnlyTaskApiCommentEditPolicy`). Missing, deleted, out of reach or not editable: 404 alike.
- **`TASK_003` names the task (Beacon spec 234).** The 409 a task that cannot be numbered answers (its scope's counter
  belongs to a look-alike scope, 2.15.1) now carries `task_id` and `scope`, so a client that writes many tasks at once
  does not bisect its batch to find the offending one. `scope` is the key the caller's **own** board asks for, never the
  look-alike key stored for another tenant, and nothing else of the task is exposed. It stays a request-level refusal,
  never an entry of a bulk update's `failures`: the write is all or none.
  `Exception\TaskNumberScopeConflictException` carries `$scopeKey` and `$taskId` (no number: the conflict is raised
  before one is reserved).
- **`TaskBoardEvents::TASK_STATUS_CHANGED`** (`TaskStatusChangedEvent`): after every column change of a task, whatever
  the channel: task and column snapshots, actor id, channel / operation, and `$toType` (always null until column types
  arrive in 2.17).
- **Dependents**: `TaskDependencyManager::dependentsOf(Task)` and `Repository\TaskDependentsLookupInterface`
  (implemented by `DoctrineOrmTaskDependencyRepository`): the tasks blocked by a task, of its tenant only.
- **Task templates** (Beacon spec 234, FR-061; opt-in `task_templates.enabled`). A template belongs to one board or one tenant and holds a name, a Markdown description body, default tags and a default column (board templates: a column id of the board; tenant templates: `default_column_name`, applied only on an exact `===` match on the board, else the board's first column).
  - Table `task_board_task_templates` (attribute and XML mapping, additive), `Template\TaskTemplateManager` (create, update, delete, list, `usableOn()`, `usable()`, `prefill()`).
  - Host port `Template\TaskTemplatePermissionInterface` (`canManage` / `canUse`, framework-free VOs `TaskTemplateScope` and `TaskTemplateSnapshot`), option `task_templates.permission`; the default `DefaultTaskTemplatePermission` falls back to the existing rights (manage board / `security.manage_roles` / add tasks).
  - Add-task dialog: a *Template* picker (`TaskTemplatePickerType`) that pre-fills the description, the tags and the column, all still editable.
  - A template's default column reaches `TaskManager::create()` through the same `TaskFormData::columnId` an explicit `status` uses, so the column transition policy decides on it too: a template pointing at a *humans only* column is refused with that policy's code and message, byte for byte the answer that `status` would give (`ApiTemplateTransitionPolicyTest`).
  - Task API: `GET /v2/list/{id}/taskTemplate`, and `template_id` on `POST /v2/list/{id}/task` (body fields win over the template; any template the board does not offer is the same 400). `TaskApiTemplateOperations` for in-process (MCP) callers.
  - Templates as data for configuration presets (Beacon block W, TaskBoard 2.17 board configuration): `TaskTemplateManager::export()` / `replaceAll()`, `TaskTemplateSnapshot::toArray()`, `TaskTemplateData::fromArray()`.
  - `TaskBoardResourceAccessChecker::canManageTenantTemplates()`.

### Security (audit PC, before the 2.16.0 tag)

- **PC-M1 — the *humans only* gate can no longer be lifted through the column endpoints.** An `api` / `mcp` caller
  (`HumansOnlyColumnTransitionPolicy::MACHINE_CHANNELS`) with a Full token and the manage-columns right could clear a
  column's `humans_only`, move a task in and set it back; delete a column so that its tasks landed in a humans-only one
  (the policy was never asked); or reorder / add columns so that a humans-only last column (the completion column) was
  no longer last. Now, before anything changes, `PUT /v2/list/{id}/status/{status_id}` with another `humans_only`
  value, `POST /v2/list/{id}/status` with `humans_only: true` or while the last column is humans-only,
  `DELETE /v2/list/{id}/status/{status_id}` of a humans-only column and `PUT /v2/list/{id}/status/order` that moves a
  humans-only last column away from the end answer **403 `STATUS_HUMANS_ONLY`**, byte for byte the answer a refused
  `status` gets (`TaskApiRefusal::humansOnly()`, `HumansOnlyColumnTransitionPolicy::MESSAGE`). Sending a column's
  current `humans_only` value is accepted; a host's own channel label and the board UI are not affected.
  `BoardColumnManager::delete()` now asks the column transition policy for **every task it moves** (new operation
  `ColumnTransitionVia::OP_COLUMN_DELETE`), all or none: one refusal keeps the column and every task
  (`ColumnTransitionRefusedException`; the API answers the policy's 403, the board UI shows its message). It gains an
  optional trailing `?ColumnTransitionVia $via` (null: `web`) and an optional `?ColumnTransitionPolicyInterface`
  constructor argument (autowired); without an actor (a worker) the policy is not asked, as for an import.
- **PC-L1 — a refusal decided while a bulk update writes is `BULK_001`.** A stateful host rule (a WIP limit that counts
  the items already written in the same transaction) refusing the second item used to answer a plain 403; it now
  answers 409 `BULK_001` with that item in `failures` (its own status and ECODE), and every write of the request is
  still rolled back.
- **PC-L2 — tag changes are asked to the column transition policy.** When the tags of a task that stays in its column
  change (`TaskManager::update()`, `TaskManager::addTag()`, `POST` / `DELETE /v2/task/{id}/tag/{name}`, a bulk item's
  `tags`, a checklist rename), the policy is asked with the new operation `ColumnTransitionVia::OP_RETAG` (`from` and
  `to` are the same column, `tags` the new ones), so a rule keyed on `repo:` tags cannot be bypassed by moving a task
  untagged and tagging it afterwards. The bundled humans-only rule allows a retag (it is not an entry).
  `TaskManager::addTag()` gains an optional trailing `?ColumnTransitionVia $via`. A checklist rename retags every item
  in one transaction (all or none); `TaskApiChecklistOperations` gains an optional trailing `?TaskApiTransaction`.
- **PC-L3 — tenant templates and the tenant.** `DefaultTaskTemplatePermission` also asks the host's own tenant-aware
  access checker (`security.access_checker` implementing `TenantAwareTaskBoardAccessCheckerInterface`) whether the
  person may list the boards of the template's tenant before letting them manage that tenant's templates. The bundled
  checkers ignore the tenant and change nothing.
- **PC-L4 — docs.** UPGRADING now says the schema change runs **before** the deploy, and documents that a writer without
  `If-Match` / `base_revision` racing a locked write may produce the same revision number (see UPGRADING).
- REQ-SEC-006: template ids and tenant keys are matched exactly (`Support\ExactKey` on every row read back, before any cap); a tenant key with edge whitespace, control or format characters is refused on entry, and `task_board_task_templates.tenant_key` gets the binary NO PAD collation of the other key columns (2.15.1).

## [2.15.1] - 2026-11-13

Target 2.15.1 (patch). See [UPGRADING](UPGRADING.md#to-2151): MySQL / MariaDB hosts change the collation of the key
columns.

### Security

- **Tenant keys, board slugs, import external ids and task-number scopes are matched byte for byte (REQ-SEC-006, audit
  F5, Info/Low).** On a MySQL / MariaDB `*_ci` or PAD SPACE collation `acme` also matched `Acme`, `acmé` and `acme `:
  a look-alike tenant's board names showed in a tenant's board lists (`findActiveByTenant`),
  `findOneByTenant` could pick a look-alike's older board, an import treated a look-alike tenant's links as "already
  imported elsewhere", and `TaskNumberLedger::purgeScope('acme')` also deleted `Acme`'s ledger and counter. Serving a
  board was never affected (`TaskBoardTenantContext::owns()` compares with `===`).
  - The key columns get a binary NO PAD collation from the mapping listener (`tenant_key` and `slug` of the boards,
    `external_id` / `import_source` of the task links, `scope_key` of the task numbers, counters and column sets, and
    `external_id` / `scope_key` / `workspace_id` of the sync tables): `utf8mb4_0900_bin` on MySQL 8.0.17+,
    `utf8mb4_nopad_bin` on MariaDB, `utf8mb4_bin` on older MySQL. The unique `(tenant_key, slug)` key no longer folds.
  - Every lookup and list keeps only the rows whose key is `===` the one asked (`DoctrineOrmTaskBoardRepository`,
    `DoctrineTaskImportLinkLookup`, `findImportedByExternalIds`, `TaskNumberLedger::find*()`, `findByScope`, the sync
    item and directory lookups), and the first board of a tenant is chosen after the exact match, not before.
  - `purgeScope()` selects the ledger and counter rows first, keeps the exact ones and deletes them by primary key; a
    task-number reservation whose counter row turns out to be a look-alike scope's fails closed (rolled back).
  - Keys that cannot be matched exactly (edge whitespace or NBSP, control or format characters, invalid UTF-8, more
    than 191 characters) are refused where they enter: `TaskBoardTenant`, `TaskBoard::setTenantKey()`, `ColumnSet`,
    the board creator, `purgeScope()`; the default tenant resolver answers 404 for such a slug.
  - Writes on a column that still folds (before the UPGRADING ALTER) never hit a unique key and close the entity
    manager (audit OM): a provider task whose sync item exists only as a look-alike (`T1` for `t1`) is skipped and
    reported (`external_id_look_alike`), and so is a look-alike workspace's people directory (`workspace_look_alike`);
    the board creator counts a look-alike tenant's slug as taken and suffixes it; a task whose number counter is a
    look-alike scope's is refused before it is scheduled (`TaskNumberScopeConflictException`, 409 on the pages,
    `TASK_003` on the API). A sync run that still fails reopens the closed manager.
  - The request's tenant (`TaskBoardTenantContext`) answers 404 for a `{tenantSlug}` that cannot be matched exactly, for
    a resolver that refuses the tenant's key, and for a tenant whose slug is not exactly the one in the URL.
  - The calendar day annotations a host returns (`calendar.day_annotation_provider`, `assigneeDays()`) are read by the
    exact assignee id asked: the answer is re-keyed in PHP and a key that is not byte for byte one of the ids the bundle
    asked for annotates nobody, instead of being reached through PHP's array-key juggling. Hardening, in line with the
    other id lookups; the ids asked are the bundle's own (`TaskMember` of the page's tasks) and the viewer and tenant
    are passed to the provider, so no annotation crossed a tenant before.

### Fixed

- **`server_version` without a patch number is logged (REQ-SEC-006 item 8).** A MySQL `server_version` of `8.0` or `8`
  cannot select `utf8mb4_0900_bin`; the key and user-id columns then fall back to the PAD SPACE `utf8mb4_bin` and the
  bundle logs a warning. The user-id columns of `user_reference: id` also honour a MySQL older than 8.0.17 now.
- **Id-mode erasure and filters stay under the database's parameter limit (audit OC, Low).** `detachUser()` of
  `TaskTimeEntrySnapshotPurger` and `TaskSyncPeopleReassigner` update the exact rows 500 primary keys at a time, and
  the API task list and the multi-board assignee filter keep their candidates in SQL and drop only the tasks matched
  through a look-alike id, instead of binding every task id of the user (PostgreSQL accepts 65,535 parameters).

### Added

- **`SyncActorProviderInterface` is autowirable (Beacon request).** The default `ConnectionCreatorActorResolver` also
  implements the framework-free twin (same answers for a `TaskBoardSnapshot` as for the entity) and is aliased, public,
  under `Sync\Hook\SyncActorProviderInterface`, so a host moves off the deprecated `SyncActorResolverInterface` without
  copying its logic. The deprecated path is unchanged. When `sync.actor_resolver` names a service that implements the
  twin, that service takes the alias instead (audit OM TB-I1), so autowiring never hands out the bundle's default in
  place of a host's stricter resolver. See [UPGRADING](UPGRADING.md#sync-actor-resolver-the-framework-free-twin-is-autowirable).

### Tests

- `ScopeKeyLookAlikeTest`: `Bob`, `bób`, `bob `, `bob\n`, NBSP and ZWSP tenants side by side on the bundle's mapping
  (MySQL / MariaDB NO PAD, SQLite, PostgreSQL), and on key columns recreated with a folding collation (SQLite NOCASE,
  MySQL / MariaDB `utf8mb4_general_ci`) every lookup, the import lookup, the ledger, `purgeScope()` and the counter
  drop the look-alikes' rows. `ExactKeyTest`, `KeyCollationTest`, `SyncActorProviderAliasTest`,
  `DddHostEraseChunksTest` (an id-mode erasure of 66,000 rows). `TaskBoardPortsPassTest` also covers which service the
  public `SyncActorProviderInterface` alias ends up on.

### Documentation

- `docs/SECURITY.md`: record the latest AI security audits (Appendices NN, NI, KO, JY) with dates and grade (REQ-SEC-004).

## [2.15.0] - 2026-11-13

Target 2.15.0 (minor; REQ-ARCH-001 / REQ-RUNTIME-002). Every change is additive: with an unchanged configuration and
unchanged host services TaskBoard behaves exactly as 2.14.1 (the whole functional suite also runs through the new
ports, see Tests). See [UPGRADING](UPGRADING.md#to-2150).

### Added

- **Framework-free host ports (REQ-ARCH-001 rules 1 and 3).** Each host port typed with a bundle entity, the HTTP
  `Request` or an `UploadedFile` has a 2.15 twin that takes and returns only bundle value objects, scalars and the
  host's own user object:

  | Deprecated (removed in 3.0) | 2.15 twin |
  |---|---|
  | `Api\Hook\TaskApiAuthenticatorInterface` (`Request`) | `Api\Hook\TaskApiRequestAuthenticatorInterface` (`Api\Value\TaskApiRequestContext`) |
  | `Api\Hook\TaskApiAttachmentsInterface` (`Task`, `UploadedFile`) | `Api\Hook\TaskApiAttachmentStoreInterface` (`TaskSnapshot`, `Api\Value\TaskApiUpload`) |
  | `Api\Hook\TaskApiTimeEntriesInterface` | `Api\Hook\TaskApiTimeStoreInterface` |
  | `Assignee\TaskAssigneeProviderInterface` | `Assignee\TaskAssigneePolicyInterface` |
  | `Comment\TaskCommentCauseLabelerInterface` | `Comment\TaskCommentCauseTextInterface` |
  | `Comment\TaskMentionCandidatesResolverInterface` | `Comment\TaskMentionCandidatesProviderInterface` |
  | `History\TaskChangeCauseLabelerInterface` | `History\TaskChangeCauseTextInterface` |
  | `Import\BoardAwareTaskImportUserResolverInterface` | `Import\BoardScopedTaskImportUserResolverInterface` |
  | `Import\TaskImportLinkLookupInterface` | `Import\TaskImportLinkFinderInterface` |
  | `Move\TaskBoardTargetsInterface` | `Move\TaskBoardTargetIdsInterface` (board ids) |
  | `View\TaskBoardDefaultViewResolverInterface` | `View\TaskBoardDefaultViewPolicyInterface` |
  | `Tenancy\FirstBoardProvisionerInterface` | `Tenancy\FirstBoardProvisioningInterface` (the new board's id) |
  | `Sync\Hook\AttributionOnlyResolverInterface` | `Sync\Hook\AttributionOnlyPolicyInterface` |
  | `Sync\Hook\ImportedTimeEntrySinkInterface` / `OwnerlessTimeEntrySinkInterface` | `Sync\Hook\ImportedTimeEntryReceiverInterface` / `OwnerlessTimeEntryReceiverInterface` |
  | `Sync\Hook\InlineImageRewriterInterface` | `Sync\Hook\SyncedImageRewriterInterface` |
  | `Sync\Hook\PeopleInviterInterface` | `Sync\Hook\PeopleInvitationInterface` |
  | `Sync\Hook\ProviderCredentialResolverInterface` | `Sync\Hook\ProviderCredentialSourceInterface` |
  | `Sync\Hook\SyncActorResolverInterface` | `Sync\Hook\SyncActorProviderInterface` |
  | `Sync\Hook\SyncLimitCheckerInterface` | `Sync\Hook\SyncQuotaPolicyInterface` |
  | `Sync\Hook\SyncPersonResolverInterface` | `Sync\Hook\SyncPersonDirectoryInterface` |
  | `Sync\Hook\TaskAttachmentStorageInterface` | `Sync\Hook\SyncedFileStorageInterface` |

  Entities travel as snapshots copied when the port is called (`ValueObject\TaskBoardSnapshot`, `TaskSnapshot`,
  `TaskCommentSnapshot`, `TaskChangeSnapshot`, `TaskSyncDirectorySnapshot`: ids, names, dates, the creator's id; no
  entity, collection or user object). The HTTP request travels as `Api\Value\TaskApiRequestContext` (method, path,
  route, client IP, the `Authorization` credential and the headers; never a cookie), an upload as
  `Api\Value\TaskApiUpload` (temporary path, client name and client-supplied type, size, `open()`). Audit OC-I1: the
  request context never copies credential headers (Authorization, Proxy-Authorization, PHP-Auth-*, Cookie, X-Api-Key),
  so a host may log it; the credential reaches the host only as `$credential`.
- **Selection rule.** A service implementing the deprecated interface is asked exactly as in 2.14, also when it
  implements the twin too. A service implementing only the twin is asked through an internal gate (`Port\*Gate`). The
  same configuration keys take either (`api.hooks.*`, `assignee_provider`, `comments.*`, `history.cause_labeler`,
  `move.targets`, `board.default_view_resolver`, `tenancy.first_board_provisioner`, `sync.*`); the
  `TaskImportLinkLookupInterface` and `TaskImportUserResolverInterface` aliases too.
- `DependencyInjection\Compiler\TaskBoardPortsPass`: the build fails when a configured port service implements neither
  interface, naming the configuration key; a service whose class cannot be known before compilation (a factory) is
  checked when it is built and fails closed (`LogicException`).
- **Framework-free event accessors (REQ-ARCH-001 rules 1 and 4).** Every event that carried a bundle entity also answers
  with ids and snapshots, built on call: `getBoardId()`, `getTenantKey()`, `getBoardSnapshot()` and `getSubjectId()` on
  `BoardAccessCheckEvent`; `getTaskId()`, `getBoardId()`, `getTenantKey()`, `getTaskSnapshot()`, `getSubjectId()` on
  `TaskAccessCheckEvent`; `getColumnSetId()`, `getColumnSetScopeKey()`, `getColumnSetCreatorId()`, `getSubjectId()` on
  `ColumnSetAccessCheckEvent`; `getBoardId()`, `getBoardSnapshot()`, `getSubjectId()` on `ColumnSetQueryEvent`;
  `getBoardId()`, `getBoardSnapshot()`, `getActorId()` on `TaskBoardQuotaCheckEvent`; `getBoardSnapshot()` on
  `BoardCreatedEvent` / `BoardUpdatedEvent`; `getBoardId()`, `getTenantKey()`, `getBoardSnapshot()` (and
  `getActorId()` where there is an actor) on `BoardConnectionEvent`, `SyncStartedEvent`, `SyncPageDoneEvent`,
  `SyncFinishedEvent`, `SyncFailedEvent`; `getTaskId()`, `getBoardId()`, `getTenantKey()`, `getTaskSnapshot()` on
  `TaskRemoteArchivedEvent` / `TaskRemoteRestoredEvent`; `getTaskSnapshot()` on every task event;
  `getCommentSnapshot()` on `TaskCommentEvent` / `TaskMentionEvent`.
- `Sync\Time\ImportedTimeEntryCandidate::personId()`, `personProviderUserId()`, `taskId()`, `boardId()`;
  `Api\TaskApiWhere::reachesBoardOf(string $boardId, ?string $tenantKey)`.

- **XML mapping (opt-in, REQ-ARCH-001 rule 2).** `nowo_task_board.database.mapping_type: xml` maps the 28 TaskBoard
  entities from `src/Resources/config/doctrine-xml/<group>/*.orm.xml` (core, sync, api, recurrence, number; same
  tables, columns, indexes, constraints and enum types; the groups are still mapped only with their feature on). The files
  are generated from the attributes by `scripts/generate-xml-mapping.php`, leave implicit names implicit (same metadata
  under any naming strategy) and live deliberately outside `Resources/config/doctrine`, so DoctrineBundle's auto_mapping
  keeps the attributes by default. `XmlMappingParityTest` compares every entity's metadata and the generated schema.
- **User references by id (opt-in, REQ-ARCH-001 rule 2).** `nowo_task_board.database.user_reference: id` replaces the
  `ManyToOne` relations of the TaskBoard rows to `user_class` (creator, author, member, time entry, history, team member,
  column set, sync people, ... 18 fields) by the user's id in a string column of the same name
  (`Doctrine\Type\TaskBoardUserRefType`, 128 characters, indexed; `utf8mb4_bin` on MySQL / MariaDB): `user_class` no
  longer needs to be a Doctrine entity. A row read back holds a `ValueObject\TaskBoardUserRef` (its `getId()`); a host
  user object written to a field is stored by its `getId()`. The host port `User\TaskBoardUserDirectoryInterface`
  (`user_directory`, required in this mode) finds a user by id when the bundle needs more than the id (labels, avatars,
  a queued sync run's actor, the multi-board people, a picker's choice); every answer is re-checked byte for byte in PHP.
  `User\TaskBoardUsers` (per unit of work, cleared at every boundary) keeps the bundle's queries mode-aware (the column
  itself instead of `IDENTITY()`, the id as the parameter). The member picker offers the assignee policy's users by id.
  Twig: `nowo_task_board_user(user)` (the host user behind a reference; used by the member label and avatar partials).
  Audit OC (before release): the id columns compare in binary **NO PAD** (`utf8mb4_0900_bin` on MySQL 8,
  `utf8mb4_nopad_bin` on MariaDB; `utf8mb4_bin` is PAD SPACE, so `"u "` matched `"u"`); an id with whitespace at an
  edge, a control or format character (ZWSP, BOM, bidi marks), invalid UTF-8 or more than 128 characters is refused by
  `TaskBoardUserRef` and by the column type before any write (also on SQLite); every lookup by user id (trackable tasks,
  the API's owner and assignee filters, the multi-board assignee filter, the API time entries and their billable
  change, the people mapped to a user) keeps only the rows whose id is equal byte for byte in PHP; the erasure ports
  (`TaskTimeEntrySnapshotPurger::detachUser()`, `TaskSyncPeopleReassigner::detachUser()`) refuse an id that cannot
  be matched exactly, select the rows, keep the exact ones and update them by primary key, and
  `TaskRecurrenceManager::forgetAuthor()` keeps only the exact author's series.

- **Private entity manager for the TaskBoard rows (opt-in, REQ-ARCH-001 rule 6).**
  `nowo_task_board.database.private_entity_manager: true` makes every bundle service read and write through
  `Doctrine\TaskBoardEntityManager`, a private manager created on the connection, mapping, caches, proxies and event
  manager of `database.entity_manager` (where 2.14 wrote; the tables do not move, the bundle's and the host's Doctrine
  listeners still run). A TaskBoard write then never flushes a change the application left pending in its own manager,
  never clears it, and a failed TaskBoard write closes only the private manager (created again on the next call). Adapted
  from AuthKitBundle 1.46.0 (audits NV, NJ-2, NJV-1, NF-2, NF2-2, NF3-2, NF-7):
  - `PrivateEntityManagerReferencesPass` moves every bundle service (inline definitions, iterators, method calls and
    properties included) from the source manager, the manager `EntityManagerInterface` autowires and the `doctrine`
    registry onto the private manager and `Doctrine\TaskBoardManagerRegistry`; host services are never touched.
  - Dropped at every outermost unit-of-work boundary (main request start and `kernel.terminate`, Messenger message
    received / handled / failed, outermost console command; through `TaskBoardRequestBoundarySubscriber`, after the
    request memos) and at `kernel.reset`: the manager is dropped FIRST, then its pending changes are inspected (logged as
    a warning by entity class and count, never a value) and cleared, each step guarded.
  - Recreated when closed, when the connection changed, or when a rollback undid rows it wrote (DBAL 4 middleware
    `PrivateEntityManagerMiddleware`, attached only to the source manager's connection); the source manager's SQL filters
    are mirrored on every call.
  - Bundle-owned repository factory (audit NZ's corrected pattern, `Doctrine\PrivateManagerRepositoryFactory`): service
    repositories (`ServiceEntityRepositoryInterface`, e.g. a host entity a host listener loads through the private
    manager) come from the source factory; every other repository is built and kept per manager, so a replaced manager
    is collected.
  - Host objects on TaskBoard rows (the `user_class` creator, author, member, ...) that the private manager does not hold,
    such as the security user, are adopted before each flush as the private manager's reference to the same id; the
    application's instance is never written.
  - Users read back from TaskBoard rows are the private manager's instances: host hooks and listeners compare users by
    id, never with `===` (audit OC-L1; UPGRADING).
  - Audit OC-I2: the worker-mode refreshes before an access decision or a time total (`TaskAccessGuard`, the time
    aggregators) use the manager that holds the task (`Doctrine\ManagerOf`), not only the one `getManagerForClass()`
    names (DoctrineBundle 3.4 with a dedicated `database.entity_manager` named the default one and the refresh was
    skipped). This also applies without the option.
  - `Doctrine\TaskBoardSavepoint`: a TaskBoard DBAL / bulk DQL write inside an open transaction runs in a savepoint
    (`nowo_task_board_*`) with the option on; when rolling back to it fails too, the ORIGINAL exception is rethrown.

### Deprecated

- The 21 port interfaces above (`@deprecated`; Symfony's DebugClassLoader reports an application class implementing
  one in debug mode). They keep working unchanged until 3.0.
- The entity members of the events (`getTask()`, `getBoard()`, `getComment()`, `getMember()`, `getColumnSet()`,
  `getFromColumn()` / `getToColumn()`, the public `$board` / `$task` properties), of `ImportedTimeEntryCandidate`
  (`$person`, `$task`) and `TaskApiWhere::reachesBoard(TaskBoard)`; removed in 3.0. No runtime deprecation is triggered.
- `nowo_task_board.database.private_entity_manager: false` (explicit) is deprecated: the private entity manager becomes
  the only mode in 3.0. The default (`null`, not set) keeps the 2.14 behaviour without a deprecation.

### Changed

- The prepended Doctrine mappings of the sync, API, recurrence and task-number entities no longer set `alias`
  (deprecated by DoctrineBundle 3.4, whose deprecation failed the Symfony 8.1 cells). Nothing in the bundle used the
  `TaskBoardSync:Entity` short names; a host that did writes the class name instead.
- `Board\BoardScopeField::$formType` is documented as a plain `class-string` (the value object no longer names the
  Symfony Form component); the New board form narrows it. Same value, same behaviour.
- The bundle injects the comment and history cause labelers, the first-board provisioner, the people inviter and the
  time-entry sink through `nowo_task_board.port.<name>` aliases of the configured services (the gates when needed).

### Tests

- `PortsTest` (the selection rule of all 21 ports: deprecated, both, twin only, neither), `PortGatesTest` (every gate
  passes snapshots, values and answers through), `SnapshotsTest`, `TaskBoardPortsPassTest`,
  `FrameworkFreeEventAccessorsTest`, `TaskApiFrameworkFreeValuesTest`.
- `PortEquivalenceTest`: the decisions 2.15 routes through `Port\Ports` equal the LITERAL v2.14.1 code
  (`tests/Support/Legacy/Legacy2141PortDecisions.php`) for every service 2.14 could have, and a twin-only service gives
  the answer of its 2.14 equivalent.
- Private entity manager: `PrivateEntityManagerKernelTest` (board, task, comment, time and update writes leave a pending
  application insert unflushed; the security user is adopted; a failing write closes only the private manager),
  `LegacyEntityManagerKernelTest` (the default flushes it, as 2.14), `PrivateEntityManagerPartsTest` (rollback
  middleware, replacement rules, boundary drop that cannot be poisoned, logged changes by class only, filters,
  repositories, registry, savepoints with the original exception rethrown, build pass), `PrivateEntityManagerConfigTest`
  (the option, the deprecation, the references pass on bundle and host services).
- `PrivateEntityManagerWorkerTest` (REQ-RUNTIME-002): one kernel driven through `http_kernel->handle()` / `terminate()`
  (no services resetter), 300 iterations of two users writing (task moves) and reading their boards: no cross-user
  data, the private manager dropped after every request, a pending application change never flushed, memory within
  4 MiB after the warm-up.
- **Private-manager replay** (`phpunit.private-em.xml.dist`, a CI job): the functional suite runs with the option on and
  the fixtures written through the private manager (774 of the 874 functional test cases; the 13 classes left out keep entity objects across a
  request boundary, mix the application's manager with the bundle's, or assert the application's manager is closed after
  a failure, which the private manager deliberately changes).
- `DddHostKernelTest` (REQ-ARCH-001, the DDD host): users that are plain PHP objects (no Doctrine entity, an in-memory
  security provider), XML mapping, the private entity manager, user references by id and host ports through their twins
  only — board, columns, tasks, comments, members and time through the ports, the board and task pages with the names the
  directory gives, an HTTP write, a look-alike id in another case kept apart. `XmlMappingParityTest`,
  `TaskBoardUserRefTypeTest`, `TaskBoardUsersTest`, `UserReferenceConfigTest`.
- **Twin replay** (`TASK_BOARD_TEST_PORTS=twin`, a CI job): the whole functional suite runs with every host stub of the
  test kernels behind an adapter that implements ONLY the 2.15 twin (`tests/Support/TwinPorts`): 870 tests, same results.

## [2.14.1] - 2026-11-12

### Fixed

- **Console boundary counter could leak in a long-lived process (audit NM / NL2-v, REQ-RUNTIME-002).**
  `TaskBoardRequestBoundarySubscriber` (avatar URLs, repeat icons, synced people, change cause, held task events)
  counted running console commands with `++` on `console.command` (4096) and `--` on `console.terminate` (-4096). A
  terminate listener in between that threw or stopped the propagation, or a `console.error` listener that threw
  (Symfony then skips `console.terminate`), left the count above zero forever. In a kept-alive worker that ran a command
  in-process, the memos were then never cleared between HTTP requests again (another user's avatar URLs, synced people
  or held task events could outlive their request). A `console.command` listener above 4096 that stopped the propagation
  skipped the `++` while the `--` still ran, so a nested command cleared the outer unit mid-run (an open
  `TaskEventDeferral::defer()` lost its held events).
  Every unit-of-work state change now happens at `PHP_INT_MAX` (new internal `EventListener\UnitOfWorkFrames`): each
  console run is a frame keyed by its command and its input, saving the message flag and the main-request depth when
  pushed and restoring them when removed; `console.terminate` removes only its own frame (and the frames above it);
  `console.error` marks the frame, and a marked frame whose terminate never came is dropped at the next main request,
  received message, command or worker stop; the end of a main request or a message drops the frames started inside it.
  Main requests are counted, so a main request handled in-process inside another one no longer clears or ends the outer
  unit. A top-level `kernel.terminate` (nothing else running) resets the main-request count, so a request whose `kernel.finish_request` never came (a throwing `kernel.exception` listener) does not stop the next clears (audit NR-b M1). The clears keep their priorities. `WorkerMessageSkipEvent` (dispatched by `messenger:failed:retry`) ends the
  in-flight message.
- **A failing calendar day annotation provider no longer breaks the calendar (audit NN-L1).** An exception from
  `calendar.day_annotation_provider` is caught: the page renders without annotations (the 2.13 page) and a warning is
  logged with the exception.
- **A drag that snaps back onto the same dates writes nothing (audit NN-L2).** With `skip_non_working_days`, a bar dropped
  on a non-working day that snaps back to its own dates goes back without a request, a "moved" toast or an Undo
  (`movedDates()` in `calendar-utils.ts`; `task-board.js` rebuilt).
- **The calendar's assignee filter has a stable order (audit NN-I2).** `DoctrineOrmTaskRepository::findCalendarAssignees()`
  sorts the people by label, then id, so the filter lists them in the same order on SQLite, MySQL and PostgreSQL.

### Documentation

- USAGE: the calendar query does not apply the per-task `TASK_ACCESS_CHECK` (since 2.1); hosts that deny single tasks
  apply the same rule in their annotation provider (audit NN-I1).

### Tests

- `tests/Functional/ConsoleBoundaryCounterTest.php`: a real console `Application` and a real `HttpKernel` sharing one
  event dispatcher, no services resetter: throwing `console.terminate` / `console.error` listeners (also at
  `PHP_INT_MIN`), a self-recursive command whose inner run fails with a throwing error listener, an unknown nested
  command, a nested command whose `console.command` propagation is stopped above 4096, a throwing
  `WorkerMessageReceivedEvent` listener inside an in-process consume command, a nested main request, a failed command
  inside a failed message followed by a worker stop, and the unchanged clears. The calendar markup equivalence test
  compares the whole page again (the assignee filter is no longer masked).

## [2.14.0] - 2026-11-12

Target 2.14.0 (minor; Beacon spec 204). Opt-in (`calendar.day_annotation_provider`); without a provider the calendar markup is the 2.13
markup. No schema change, see [UPGRADING](UPGRADING.md#to-2140).

### Added

- **Calendar day annotations.** Host port `Calendar\CalendarDayAnnotationProviderInterface` with bundle-owned value
  objects only (`CalendarViewer`, `CalendarDateRange`, `CalendarDayAnnotation`: date, `nonWorking`, label, `CalendarDayKind`
  holiday / weekend / absent / other, `CalendarDayAnnotations`). `viewerDays()` shades and labels the viewer's days
  (`is-non-working`, `is-day-<kind>`, `data-non-working`); `assigneeDays()` is asked once for every assignee of the page,
  and a task whose assignee is off on its due date, its start or a day between (within the page) carries a warning badge
  with the host's label (`has-off-day`, `_calendar_off_day.html.twig`). One query for the page's assignees.
- `calendar.skip_non_working_days` (default `false`): with a provider, a dragged task (body or edge) lands on the next
  working day of the viewer.
- The bundle renders only the host's label, escaped in Twig; the JavaScript never writes a label. Hosts must not pass
  medical or other special category reasons (use "Absent").
- `TaskCalendarBuilder::build()` takes an optional `CalendarAnnotations`; `TaskCalendarDay::$annotation` and
  `TaskCalendarEntry::$warnings` (optional, at the end of the constructors).

## [2.13.0] - 2026-11-12

Target 2.13.0 (minor; Beacon D-20261112-01, "All tasks" of an organisation). Opt-in (`multi_board.enabled`); no schema
change. New runtime requirements `nowo-tech/controller-kit-bundle` `^2.3` and `ext-zlib`, see
[UPGRADING](UPGRADING.md#to-2130).

### Added

- **Multi-board task list** (`multi_board.enabled`, route `nowo_task_board_tasks` at `multi_board.path`, default
  `/tools/task-board/tasks`): the tasks of every board a host lets the viewer read, on UiKit's list scaffold, with a
  Board / Project column, filters (board / project, assignee or *Unassigned*, column, status, priority, due range,
  overdue, label, search by title or `#number`), server-side sort and pagination (10 / 25 / 50 / 100 per page), and
  `?view=people`: the workload per assignee (open, overdue, done, estimate of the open tasks, time logged).
- **Export** (route `nowo_task_board_tasks_export`, `<path>/export.csv` and `.xlsx`): the filtered, sorted set, streamed
  in batches of 500 and capped by `multi_board.export.max_rows` (default 5000; above it the header
  `X-Task-Board-Export-Truncated`). `Cache-Control: no-store`. Every text cell is guarded against formula injection
  (ControllerKit's `CsvSafeWriter`: a leading `=`, `+`, `-`, `@`, tab or CR gets a `'`); the XLSX writes inline strings
  only (never a formula) through a streaming writer of the bundle, without a spreadsheet library.
- **Host port** `MultiBoard\MultiBoardScopeProviderInterface` (`multi_board.scope_provider`) with bundle-owned value
  objects only (`MultiBoardViewer`: user id and the route variables of the page; `MultiBoardScope` /
  `MultiBoardScopeEntry`: board id, optional label and tenant slug). **Deny by default**: no provider or an empty scope
  shows an empty list and the export answers 404. Every board of the scope is re-checked with
  `TaskBoardResourceAccessChecker::canViewBoard()` (and its BOARD_ACCESS_CHECK listeners), so the provider can narrow
  access, never widen it; a crafted board or assignee filter only narrows the checked set.
- **Read port** `MultiBoard\MultiBoardTaskListInterface` (`boards()`, `page()`, `workload()`, `export()`): values in,
  read models out (`MultiBoardTaskRow`, `MultiBoardWorkloadRow`, ...), for a DDD host that calls it by ids. Unlike the
  command ports it checks access itself on every call (manage roles only for the signed-in viewer; with tenancy, the
  board's tenant must resolve for the viewer). `TaskBoardResourceAccessChecker::canViewBoard()` gains an optional
  `$manageRoles` argument (default `true`).
- Audit NI (before the tag): an `assignee` filter without a task on the readable boards is ignored (text for an integer
  id no longer reaches the database: no 500 on PostgreSQL); a label export cut by `scan_max_rows` sends
  `X-Task-Board-Export-Partial: 1`; each joined assignee and label, and text behind leading whitespace, is guarded
  against formula injection; `scan_max_rows` is capped at 50000.
- One bounded query per page over the IN-list of checked board ids, plus one for the page's assignees and one for their
  names (no N+1); the label filter compares the JSON tags in PHP over at most `multi_board.scan_max_rows` tasks (the page
  says when the result is partial).
- Options `multi_board.*` and `web_ui.templates.tasks` (`@NowoTaskBoardBundle/manage/tasks.html.twig`,
  `_multi_task_row.html.twig`, `_multi_workload_row.html.twig`); translations in the seven locales.

### Changed

- `nowo-tech/controller-kit-bundle` `^2.3` (CSV writer and download headers) and `ext-zlib` (XLSX) are required.

## [2.12.0] - 2026-11-12

Target 2.12.0 (minor; Beacon D-20261111-12, request 2026-11-11 *billable time entries*). One nullable column
(`task_board_task_time_entries.billable`), see [UPGRADING](UPGRADING.md#to-2120).

### Added

- **Billable manual entries.** `TaskTimeEntry::getBillable()` (nullable: rows before 2.12 are "not set") and a
  *Billable* switch in *Log time* (FormKit's `switch` checkbox, preset to the host default). Each entry shows a
  *Billable* / *Not billable* badge, and its author or a board manager gets a *Mark billable* / *Mark not billable*
  button (route `task_time_billable`, POST + CSRF).
- **`Service\TaskTimeBillableService`** (public): the authorised, audited setter. Refused
  (`Exception\TaskTimeBillableRefusedException`) for an unknown entry, a deleted user's entry (never changes), an actor
  who is neither its author nor a board manager with the edit right (`TaskBoardResourceAccessChecker::canEditTimeEntry()`,
  the rule of `canRemoveTimeEntry()`), and a locked or submitted period. Each change is a history row
  (`TaskChangeType::TimeBillable`: old and new value, the entry's day) and `TASK_TIME_BILLABLE_CHANGED`
  (`TaskTimeBillableChangedEvent`: ids, old and new value, cause) after the save.
- **Ports** `Service\TaskTimeBillableDefaultProviderInterface` (default of a new entry; `NullTaskTimeBillableDefaultProvider`)
  and `Service\TaskTimePeriodLockInterface` (`NoTaskTimePeriodLock`), with no framework type (REQ-ARCH-001). With
  TimeTrackBundle installed (optional dependency) they are bridged: the default is TimeTrack 1.21's
  `TimeEntryBillableDefaultProviderInterface`, the lock TimeTrack's timesheet `PeriodLockGuard` (>= 1.13).
- **Task API:** `billable` on `POST /v2/team/{team_id}/time_entries` (no longer listed as not supported), the real flag in
  every time entry answer (`false` when not set, as before), and `PUT /v2/team/{team_id}/time_entries/{timer_id}` with
  `billable` for the token's own entries (`Api\Hook\TaskApiTimeEntryBillableInterface`, implemented by
  `ManualTaskApiTimeEntries`; 409 `TIME_004` in a locked period, 403 `TIME_003` for a store without it). Deleting time
  through the API is still refused (403 `TIME_002`, spec 213).
- `TaskTimeLogger::log()` takes a trailing `?bool $billable` (null = the host default).
- Demo migration `Version20261112120000`.
- **Audit MV (before the tag):** the TimeTrack lock bridge compares the entry's calendar day with each timesheet's
  `periodStart` / `periodEnd` instead of noon in the PHP time zone, which checked the wrong day for UTC+13/+14 timesheets
  (MV-B-L1); the lock check and the save run in one transaction of the bundle's entity manager, and the guarding
  timesheets are read with TimeTrack's locking read when the store has it (MV-B-L2); the API docs say PUT is own entries
  only (the board-manager path is the task page) and `TIME_004` means a locked period (MV-B-L3); the API audit row of
  a billable change names the board (MV-B-I2).

### Changed

- `nowo-tech/form-kit-bundle` requirement raised to `^2.22` (the `switch` checkbox option).
- Dev dependency `nowo-tech/time-track-bundle` raised to `^1.21` (also in the CI install scripts), so the tests and
  PHPStan run against TimeTrack's real billable default port. The optional runtime integration still works with older
  TimeTrack: the billable bridge is registered only when the port exists.

## [2.11.1] - 2026-11-12

Target 2.11.1 (patch).

### Fixed

- **Request memos cleared at every boundary, also without the services resetter (REQ-RUNTIME-002).** The avatar URLs
  (`TaskMemberAvatars`), the repeat icons (`TaskRecurrenceView`), the synced people rows (`SyncPeopleViewProvider`),
  the change cause (`TaskChangeContext`) and the held task events (`TaskEventDeferral`) were cleared only by
  `kernel.reset`. **Precondition:** with the stock Symfony kernel the services resetter still runs before every request
  (also with `FRANKENPHP_RESET_KERNEL=false`), so nothing leaked there. It leaked only where the resetter does not run:
  a host that removes, replaces or bypasses `services_resetter` (or drives `http_kernel` directly), Messenger consumers
  started with `--no-reset`, and long-running console commands. There, a page or message could show an avatar URL, a
  repeat icon or a synced task row resolved during an earlier request, possibly another viewer's (the memos are keyed by
  user, board or task; board access is still checked per request, so no board of another tenant was shown), the memos
  grew with every board viewed, a change cause left by a crashed write could label later history rows, and a write that
  died between `TaskEventDeferral::begin()` and its rollback held, and never dispatched, every later task event of that
  worker. The new
  `EventListener\TaskBoardRequestBoundarySubscriber` clears them on the main `kernel.request` and `kernel.terminate`
  (outside a Messenger message and a console command: a page rendered in-process by a handler or a command, e.g. a PDF
  or an e-mail, keeps the outer unit and its open `defer()`), on
  Messenger message received / handled / failed, and on the start and end of the outermost console command (a command
  run in-process from another command, a message handler or a controller does not clear the state of the unit that
  runs it). The services keep `ResetInterface`; the subscriber gets them as `IGNORE_ON_UNINITIALIZED` references and
  never builds one. Test: `WorkerStateTest` (one kernel, no resetter, alice and bob over 500 iterations: 1,500
  requests and 1,000 messages, memory within 4 MiB) and `TaskBoardRequestBoundarySubscriberTest`.
  **Affected:** 1.23.0 to 2.11.0 (`TaskMemberAvatars` from 1.23.0, `SyncPeopleViewProvider` from 1.25.0,
  `TaskRecurrenceView` / `TaskChangeContext` / `TaskEventDeferral` from 2.3.0), only under the precondition above: the
  services resetter removed, replaced or bypassed (or `http_kernel` driven directly), `messenger:consume --no-reset`, or
  a long-running console command.
- **Dev dependency:** `symfony/messenger` (worker test only; the bundle does not require it).

### Changed

- **Igor worker-state audit in CI.** A standalone `igor` job runs `composer igor` (igor-php, REQ-CS-008) on every push
  and pull request (with `COMPOSER_PROCESS_TIMEOUT=0`: the audit of the 537 service files takes about 8 minutes). The
  ten KO igor reported are false positives, each justified inline with `// @igor-ignore` and recorded in
  [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md#igor-findings-2026-11-11): calls on stateless `readonly`
  services, responses and query builders built per call, transaction closures run at once, a per-request input value
  object, a DQL function node and the host rate limiter's failed-token counter. No behaviour change.

## [2.11.0] - 2026-11-10

Target 2.11.0 (minor). DDD / hexagonal P2 round (audit 2026-11-09, G3 and G4 leftovers). No schema change; see [UPGRADING](UPGRADING.md#to-2110).

### Added

- **Id-only events of time entries, dependencies, links and member removal (DDD audit G3).** Dispatched after the write is saved, through `TaskEventDeferral` like the 2.10 events (after the commit of a deferred write, a sync page or an import; dropped on a rollback or a dry run; a listener failure after a committed sync page or import is logged): `TASK_TIME_LOGGED` / `TASK_TIME_REMOVED` (`TaskTimeLoggedEvent` / `TaskTimeRemovedEvent`: `entryId`, `taskId`, `boardId`, `tenantKey`, `userId`, `minutes`, `loggedOn`, `actorId`, `cause`), `TASK_DEPENDENCY_ADDED` / `TASK_DEPENDENCY_REMOVED` (`dependencyId`, `taskId`, `sourceTaskId`, `targetTaskId`, `type`, `boardId`, `tenantKey`, `actorId`, `cause`), `TASK_LINK_ADDED` / `TASK_LINK_UPDATED` / `TASK_LINK_REMOVED` (`linkId`, `taskId`, `boardId`, `tenantKey`, `linkType`, `objectType`, `objectId`, `actorId`, `cause`; host object links included, no URL or label) and `TASK_MEMBER_REMOVED` (`TaskMemberRemovedEvent`: `memberId`, `taskId`, `boardId`, `tenantKey`, `userId`, `role`, `actorId`, `cause`).
- **Where they come from (audit KO-T-L1):** the board and API actions only (the four services above); a provider sync, the people sync, the import updater and the cross-board move change members, links and dependencies without them, and erase dispatches only `TASK_ERASED` / `BOARD_*`. Documented in USAGE and UPGRADING.
- **`TaskEventDeferral::dispatchSaved()` (audit KO-T-L2):** the new events go through it: held inside a deferred write, otherwise dispatched at once with a listener's failure logged as an error instead of failing the saved write (as 2.10.1 does for copies and occurrences). The older events are unchanged.
- **Ports of the move and the eraser (DDD audit G4):** `Move\TaskMoverInterface` and `Service\TaskBoardEraserInterface`, implemented by the bundle's classes and aliased to them (public). `TaskMoverInterface` checks no access (like the 2.10 ports); `TaskBoardEraserInterface` keeps the eraser's own `canManageBoard()` check and signed cursors, and its docblock asks every implementation to keep them. The bundle's controllers and API keep calling the classes.

### Changed

- `TaskTimeLogger`, `TaskDependencyManager` and `TaskLinkAttacher` take an optional last `?EventDispatcherInterface $eventDispatcher` (wired to `TaskEventDeferral`); `TaskMemberAssigner` uses its existing one for the removal too. Hand-built instances without it dispatch nothing, as before.

## [2.10.1] - 2026-11-10

Target 2.10.1 (patch). No schema change; see [UPGRADING](UPGRADING.md#to-2101).

### Fixed

- **Calendar list mode `data-testid` (Symfony Beacon).** The Reschedule button of a list-mode row (and of a week on a phone, `_calendar_row.html.twig`) now carries `data-testid="nowo-task-cal-reschedule"`, like the chip's button. The other list-mode controls already matched the month: `nowo-task-cal-add` on each day's `+`, and the shared `nowo-task-cal-reschedule-dialog` / `nowo-task-cal-add-dialog`.
- **A failing listener no longer fails a saved copy or occurrence (audit JY-I6).** `TaskCopier::copy()` / `copyChecked()` and `TaskRecurrenceManager::createOccurrence()` dispatched their held events after the commit and then rethrew the first listener failure, so a host listener throwing on a copy's `TASK_CREATED` reported a saved copy as failed (an error page), and a retry created a second copy. They now use the non-throwing path of the 2.10.0 import and sync fix (JY-L1): every held event is still sent, and a listener's failure is logged as an error (`TaskBoard: a listener failed after the write was committed`). `TaskEventDeferral::defer()` gains an optional `bool $rethrow = true` for this; the default is unchanged.

## [2.10.0] - 2026-11-10

Target 2.10.0 (minor). For Symfony Beacon 1.144: spec 214 (calendar) and spec 216 (automations). Additive: one nullable column and one index; see [UPGRADING](UPGRADING.md#to-2100).

### Added

- **Task numbers in the Task API (spec 214).** Every task body carries `number` (the readable `#123`, null with `task_numbers` off). `GET /v2/task/{number}?custom_task_ids=true` finds a task by its number, ClickUp's parameter for custom task ids, within `space_id` (numbers per tenant) or `list_id` (any scope); `123` and `#123` are accepted and `team_id` is accepted and ignored. A number a moved task left behind is not found. Access is checked as for `GET /v2/task/{id}`.
- **Index `idx_task_board_sync_items_board_custom` (spec 214)** on `task_board_sync_items (board_id, custom_id)`, for a host's search by a provider's custom id (`DEV-123`).
- **Calendar `data-testid`s (spec 214):** `nowo-task-cal-chip`, `nowo-task-cal-reschedule` (the chip's button), `nowo-task-cal-reschedule-dialog`, `nowo-task-cal-add` (the per-day `+`) and `nowo-task-cal-add-dialog` (the add-task dialog of the calendar).
- **A comment's cause (spec 216, FR-014).** `TaskComment::getCause()` (column `cause`, nullable): a comment added inside `TaskChangeContext::run('rule:42', …)` keeps that cause, like the history rows. `comments.cause_labeler` (a `Comment\TaskCommentCauseLabelerInterface`) prints it next to the author, e.g. "(rule: Review goes to Luis)" (`data-testid="nowo-task-comment-cause"`); without a labeler nothing is shown.
- **Warnings before deleting a column (spec 216, FR-010).** `TaskBoardEvents::COLUMN_DELETE_CHECK` (`ColumnDeleteCheckEvent`, once per board page for a person who may manage the columns): `addWarning($columnId, $text)` adds plain-text lines that the edit-column dialog shows above *Delete column* (`data-testid="nowo-task-column-delete-warning"`) and repeats in the confirmation. It never refuses the deletion.
- **Id-only events (spec 216, DDD audit G2/G3)**, dispatched after the write is saved, through `TaskEventDeferral` (so after the commit of a deferred write, a sync page or an import, and dropped on a rollback or a dry run): `TASK_ARCHIVED` / `TASK_RESTORED` (`TaskArchivedEvent` / `TaskRestoredEvent`: `taskId`, `boardId`, `tenantKey`, `number`, `actorId`, `cause`), `TASK_COMMENT_DELETED` (`TaskCommentDeletedEvent`), `COLUMN_CREATED`, `COLUMN_UPDATED` (`changedFields`, `previousName`, `isRenamed()`), `COLUMN_DELETED` (`targetColumnId`, `movedTaskIds`) and `COLUMNS_REORDERED` (`columnIds`). `cause` is `TaskChangeContext::current()` at the write.
- **Scalar getters on the existing events (DDD audit G2).** Every task event (`AbstractTaskEvent`) has `getTaskId()`, `getBoardId()`, `getTenantKey()`, `getTaskNumber()` and `getActorId()`, taken when the event is made; `TaskMovedEvent::getFromColumnId()` / `getToColumnId()`, `TaskCommentEvent::getCommentId()`, `TaskMentionEvent::getCommentId()` / `getMentionedUserIds()`, `TaskMemberAddedEvent::getMemberId()` / `getUserId()` / `getRole()`, `BoardCreatedEvent` and `BoardUpdatedEvent::getBoardId()` / `getTenantKey()` / `getActorId()`. The entity getters stay.
- **Ports of the command services (DDD audit G4):** `TaskManagerInterface`, `TaskBoardCreatorInterface`, `TaskBoardEditorInterface`, `TaskCommentManagerInterface`, `TaskTimeLoggerInterface` and `BoardColumnManagerInterface` (namespace `Service`), implemented by the bundle's classes and aliased to them (public), for a host's application layer to depend on, double and decorate.
- `TaskEventDeferral::begin()` / `commit()` / `rollBack()`, for a write that opens its own transaction; `TaskChangeRecorder::currentCause()`; `Support\ActorId::of()`.

### Fixed (audit JY, before the tag)

- **JY-L1:** a sync page and an import dispatch their held events after the commit, outside their `try`: a host listener that throws no longer reports saved rows as failed (it is logged as an error). `TaskEventDeferral::commit()` dispatches every held event even when one listener throws, then rethrows the first failure (`commit(false)`: logs it instead).
- **JY-L2:** the six ports and USAGE say that they check nothing: the host checks access, tenant, plan, comment rights, `activeTaskRefusal()` and CSRF before calling them.
- **JY-L3:** `ActorId::of()` keeps a `Stringable` id (a Symfony `Uuid` / `Ulid`) and gives null for `''`, floats and booleans, so `actorId`, `getUserId()` and `getMentionedUserIds()` work for Uuid users.
- **JY-I1 / JY-I5:** a task number lookup answers only to the task's current number (a number it left behind is 404 even after it moved back), and `123\n` is not a number.

### Changed

- `BoardColumnManager::add()`, `update()`, `reorder()`, `delete()` and `TaskCommentManager::delete()` take an optional last `?object $actor` (who made the change, for the events). The bundle's controllers, the API, the sync and the import pass it. Existing calls keep working; their events carry `actorId: null`.
- `BoardColumnManager` and `TaskCommentManager` dispatch through `TaskEventDeferral` (same behaviour outside a deferred write).

### Deprecated

- `TaskBoardEvents::BOARD_LIST_RESULT`, `TASK_LIST_QUERY`, `TASK_LIST_RESULT`, `TASK_READ_ONLY_RESOLVE` and `MEMBER_LIST_QUERY` (DDD audit G5): never dispatched; removed in 3.0.

## [2.9.1] - 2026-11-09

Target 2.9.1 (patch). See [UPGRADING](UPGRADING.md#to-291).

### Fixed

- **A column with a legacy icon can be renamed again (audit JM-I7).** A column whose stored icon is no longer storable (written straight to the database, a pre-2.9.0 `default_columns` value such as `fa fa-bug`, or an icon with tag text stored before JM-I3) refused a plain rename with the icon flash ("The column was not saved…"), because the dialog posted that value back and the grid did not accept it. The grid now accepts the edited column's own current icon whatever it is (`BoardColumnManager::update()` still drops a value that is not storable), and the edit button carries `data-column-icon-storable="0"` for such an icon so the dialog opens it as "No icon", with the grid and with a host widget (`board.column_icon_form_type`) alike. Saving the dialog drops the icon, as UPGRADING already said. New Twig function `nowo_task_board_column_icon_storable(icon)`; new exported helper `dialogIcon()` in `edit-column-modal-controller.ts`.
- **A legacy bare current icon no longer shadows an offered icon (audit JM-I8).** The grid's choices are keyed by label; the edited column's own icon now has its own label key (`BoardColumnFormType::CURRENT_ICON_LABEL_PREFIX`, `current: <icon>`), so a bare name such as `bug` next to an offered `tabler:bug` (label `bug`) no longer replaces that choice: both stay valid for that column.

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [2.9.0] - 2026-11-09

Target 2.9.0. Emoji column icons (host Symfony Beacon, owner 2026-11-09: default columns with emojis).

### Added

- **A column icon may be one emoji.** `board.column_icon_form_type` / `board.column_icon_form_options` (new, default `null` / `{}` = the Tabler grid of `board.column_icons`, as before) set the widget of the column icon in the add / edit column dialogs, e.g. `Nowo\UiKitBundle\Form\EmojiPickerType` as for `board.icon_form_type`. `manage/_column_icon.html.twig` prints a value that is not an icon id as escaped text in `<span class="nowo-task-column__icon nowo-task-column__icon--emoji" aria-hidden="true">`, with any icon set (`none` included); the column name stays the accessible text. Icon ids render byte for byte as in 2.8.0.
- **`board.default_columns[].icon` takes an emoji** (📝 To do, 🚧 In progress, 👀 Review, ✅ Done) for new boards; the name stays the name.
- **`nowo:task-board:apply-default-column-icons [--board=ID] [--tenant=KEY] [--dry-run]`.** Gives the columns of existing boards that have *no* icon the icon of their `board.default_columns` entry. Columns keep no key of the entry they were made from, so they are matched by exact name (case and spaces included); a renamed column, or one with an icon, is left alone. Fill only and idempotent; prints a table of what it changed (or would change) and logs a `column_update` write per column, as the dialog does. Every active board by default, one tenant with `--tenant` (an empty `--tenant` is refused, JM-I1), one board with `--board` (refused when it is not of `--tenant`; an archived board is skipped unless `--include-archived` is given, JM-I2).
- **Task API.** A status (column) carries `icon` (one emoji, an icon id, or null) in every answer; `POST /v2/list/{id}/status` and `PUT /v2/list/{id}/status/{status_id}` take `icon` (`null` or `""` removes it). OpenAPI and *Differences from ClickUp* list it.

### Fixed

- **JI-L3 — icons under `icon_set: bootstrap-icons`.** The Gantt legend's *Dependency* key is a CSS swatch (`span.nowo-task-gantt__legend-dependency`, a dashed line in the dependency colour) like the other three keys, so it no longer depends on any icon set (it printed `bi bi-line-dashed`, which Bootstrap Icons lacks). With `bootstrap-icons`, the bundle's Tabler names that Bootstrap Icons does not have are printed as their Bootstrap Icons 1.13 names (`Support\IconNames::BOOTSTRAP`, e.g. `device-floppy` → `floppy`, `circle-check` → `check-circle`, `layout-kanban` → `kanban`) in `manage/_icon.html.twig`, the column icons of `tabler:` ids and the page header actions and tabs. Only names Bootstrap Icons does not have are mapped, so a host's Bootstrap name is never changed; `tabler-icons`, `ux_icon` and the other sets get the same names as before. The demo's admin layout loads the Bootstrap Icons stylesheet of the set it configures.
- **JM-L1 — the default grid keeps an emoji it does not offer.** Editing a column whose icon is not in `board.column_icons` (an emoji from `board.default_columns`, the fill command or the API) no longer removes it: the edit dialog adds the current icon to the grid's `<select>` and shows it in the grid as the selected choice (as text), the server accepts that value for that column, and an edit that posts no `icon` at all keeps the stored one. *No icon* still removes it.
- **JM-I3 — no hidden tag text in icons.** Board and column icons refuse Unicode tag characters (U+E0020–E007F) except in a subdivision flag (🏴 + 1–6 tag letters or digits + U+E007F); `✅` followed by tag characters is no longer accepted.
- **JI-I1.** `TaskBoardFormatTest` uses `#[DataProvider]` instead of the deprecated `@dataProvider` doc-comment.

### Security

- **Column icons are validated on every write path.** One emoji (as the board icon: at most 16 bytes, one emoji, a ZWJ sequence, flag or keycap) or an id of `board.column_icons` / `board.default_columns` (`<set>:<name>`), whatever the form type (`TaskConstraints::columnIcon()`, form and API: 400 `INPUT_002`); `board.default_columns[].icon` is checked when the container is built. `BoardColumnManager`, `ColumnSetManager::applyToBoard()` and `TaskBoardCreator` store no other value (`ColumnIcon::normalize()`: anything else becomes no icon). No free text and no markup is stored; every place that prints an icon escapes it (Twig autoescape in the header, the list groups and `data-column-icon`; JSON in the API).

## [2.8.1] - 2026-11-09

Target 2.8.1. Phone Gantt legend (audit JD-L4): CSS inside the `@media (max-width: 640px)` block of `task-board.css` only, wider windows unchanged. The Gantt and Calendar tab navs get their own accessible name and a single class attribute (requires `nowo-tech/ui-kit-bundle` `^2.42.1`).

### Fixed

- **JD-L4 — the phone Gantt legend no longer hides items behind a hidden scrollbar.** 2.8.0 kept the legend on one row that scrolled sideways with no visible scrollbar: in Spanish and German the last item ("Por encima de lo estimado" / "Über der Schätzung") was cut by about 70–80 px at 390 px (English from 360 px down) with no sign that more followed, Chromium made the list an unlabelled Tab stop, and WebKit could not scroll it from the keyboard (WCAG 2.1.1, 1.4.1). On a phone the legend now wraps (`flex-wrap: wrap`, 0.25rem gaps, 12px text, 0.35rem item padding) to at most two rows in en, es and de at 390, 360 and 320 px — still one row fewer than 2.7.0 — so every item is fully visible and nothing is focusable. The `overflow-x`, `scrollbar-width` and `::-webkit-scrollbar` rules are gone. The demo now picks its locale from the browser language (`set_locale_from_accept_language`, `de` enabled) so `phone-layout.spec.ts` checks the legend in en, es and de, in Chromium and WebKit.
- **Gantt scale tabs and Calendar mode tabs are named by their own label.** `manage/gantt.html.twig` and `manage/calendar.html.twig` passed the label to UiKit's `tabs` partial as `label`, which the partial does not read, so both `<nav>`s were announced as UiKit's default "Sections". They now pass `aria_label`: the navs are named "Scale" and "Calendar mode" (translated).
- **The Gantt scale `<nav>` and the Calendar mode `<nav>` have one `class` attribute.** UiKit's `tabs` partial printed the bundle's `attr.class` as a second `class` attribute, which browsers drop, so `nowo-task-gantt__scales` and `nowo-task-cal__modes` never reached the page. `nowo-tech/ui-kit-bundle` is now `^2.42.1`, which merges `attr.class` into the nav's class list; the existing `.nowo-task-gantt__scales { flex: 0 0 auto; }` rule now applies, with no visible change at 1280, 768 or 390 px in Chromium and WebKit.

## [2.8.0] - 2026-11-09

Target 2.8.0. Phone layout from the host mobile UX audit (2026-11-09): CSS in a `@media (max-width: 640px)` block at the end of `task-board.css`. Wider windows and the markup are unchanged.

### Changed

- **MUX-70 — Gantt on a phone.** The title column is 6rem (96px, was 10rem / 160px), with a smaller depth step (0.5rem); the full title stays in the link's `title` and beside the bar. The legend is one compact row that scrolls sideways instead of wrapping to three rows. Month and weekday labels are 12px.
- **MUX-73 — subtask indent on a phone.** A nested level indents by 0.75rem without repeating its parent row's 1.25rem side padding (about 36px less per level), first-level rows take 0.75rem side padding, and the empty leading column (UiKit's tree spacer) is dropped when no first-level subtask has subtasks of its own.
- **MUX-74 — meta texts on a phone.** The board's column count and empty-column count, the "+n" avatar, and the task status badge are 12px (0.75rem) at least, from 11px.

## [2.7.0] - 2026-11-09

Beacon override reduction (TB-15b). New include parameters only; the New board footer gains two icons and one testid.

### Added

- **TB-15b — host testids and icons on the New board form.** `manage/_board_create_form.html.twig` takes `step_testid_prefix` (default `nowo-task-board-create`: the `-scopes`, `-next`, `-details`, `-advanced` and in-dialog `-back` testids), `form_class` (extra classes of the form) and `back_icon` / `cancel_icon` / `create_icon`. `manage/_board_create_actions.html.twig` takes `back_testid` (default `<step_testid_prefix>-back`), `step_testid_prefix` and the same three icon parameters. An icon is a Tabler name for the bundle's `_icon.html.twig`, or `false` for none.

### Changed

- **Back and Cancel of the New board form carry their verb icons** (`arrow-left`, `x`), like Create's `plus`, in both partials (one action, one icon). Back gets `data-testid="nowo-task-board-create-back"`. Hosts on `ux_icon` already import both icons (USAGE "Icons").

## [2.6.0] - 2026-11-08

Beacon override reduction (TB-18, TB-19). A host that changes nothing keeps today's behaviour; the task panel root gains one attribute.

### Added

- **TB-18 — `open_single_board` per tenant path.** `tenancy.tenant_paths[].open_single_board` (`true` / `false`, not set by default) wins over the global `tenancy.open_single_board` for the indexes of that path, chosen by the route the index was reached by (`<name_prefix><routes.index.name>`). An entry without it follows the global switch, so `open_single_board: true|false` keeps working. E.g. project indexes open their only board while personal (`user-…`) and organization indexes keep the list. `?boards=1` and `tenancy.first_board_provisioner` are unchanged.
- **TB-19 — edit hook and drag hint without the toolbars.** The task panel root (`[data-nowo-task-panel]`) carries `data-nowo-task-can-edit="true|false"`: the server's `canEditTask()` for this user and task, printed whatever `task_panel.toolbar` says, and `"false"` when a host template renders the panel without `canEditTask`. With `board.toolbar: false` the board keeps the reorder hint for screen readers (`<p class="nowo-task-sr-only" id="nowo-task-board-reorder-hint">`, referenced by `aria-describedby` on `.nowo-task-board`), for whoever may manage columns. With the toolbar on, the board markup is unchanged.

## [2.5.0] - 2026-11-08

Beacon override reduction (TB-11b, TB-12, TB-13 column management decision, TB-14 to TB-17), audited in Appendix IM (Pass; IM-L1, IM-I2, IM-I3, IM-I9, IM-I11, IM-I12 addressed). A host that changes nothing keeps today's output, except the People page, which now draws UiKit's tabs and avatar instead of Bootstrap classes.

### Added

- **TB-13 — column management decision.** `BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS` (`manage_columns`) and `TaskBoardResourceAccessChecker::canManageColumns()`: the edit decision plus the host's answer, granted by default (as before). It guards the seven column write routes (`column_create`, `column_update`, `column_reorder`, `column_delete`, `column_set_create`, `column_set_apply`, `column_set_delete`: 403), the Task API's status paths (create, update, reorder, delete: 403 `ACCESS_002`), a file import that would add missing columns (the status is reported instead), and a provider sync (`__create` columns of the link or of a new map, missing status columns: `status_without_column`; `unmatchedColumns: delete`: `unmatched_columns_denied`). The board page gets `canManageColumns`; when it is false, *Add column*, the column pencil, the grip and drag handle, the reorder hint and the column-set controls and dialogs are not rendered. A `deny()` sticks against a later `grant()`, as `ACTION_TRACK_TIME`, and `markReadOnly()` on that event refuses it too. A denied board page carries no reorder URL or token. `TaskBoardSyncService::updateStatusColumnMap()` without an actor creates no `__create` column (warning logged). Reads stay on the view decision. Audited in Appendix IM (#84 Pass-Low; IM-L1, IM-I1 to IM-I3 addressed).
- **TB-14 — a moved task on its old address.** The task page of a task whose board is now in another tenant redirects (302) to the task's page in that tenant, as the `#number` alias already did, for whoever may read the task there (`canViewTask()`); `HEAD` answers as `GET`. Anyone else, a task now outside any tenant, a panel fetch and a POST keep the 404, so the task's existence is not told.
- **TB-15 — the New board form on a page without a tenant.** `manage/_board_create_form.html.twig` takes `action` (default: the current tenant's `board_create` route), `form_id` and `testid`. New `manage/_board_create_actions.html.twig` draws the dialog footer (Back on the scope step, Cancel, Create bound to `form_id`; `no_cancel`, `close_attribute` (a `data-*` name, else the kit's close), `testid`); the index dialog uses it.
- **TB-16 — `sync.badge.style: badge|strip`** (default `badge`, today's output). `strip` draws the connection as one UiKit notice (`flash_info`) with provider · list and the status on the left, UiKit's `progress` partial while a run is going (with `sync.badge.progress`, determinate and polled for managers; otherwise and for readers, indeterminate and never polled), and *Refresh* / *Connection* on the right (`manage/_connection_strip.html.twig`).
- **TB-11b — public navigation hooks.** Each group of the previous / next navigation carries `data-nowo-task-nav="view|subtasks"`, its position text `data-nowo-task-nav-position`, the `<nav>` `data-nowo-task-nav-context="panel|page"`, and the task edit form `data-nowo-task-edit-form`. The classes `nowo-task-nav--panel`, `nowo-task-nav__position` and the id `nowo-task-edit-form` stay.
- **TB-17 — `import.help_link.url_resolver`**: the service id of an `ImportHelpLinkUrlResolverInterface` that gets the `url` / `route` URL (or null) and returns the URL to link, or null for no link (e.g. a manual in the app or on a public site). With it, `url` and `route` are optional.

### Changed

- **TB-12 — People page on UiKit.** `manage/connection_people.html.twig` draws the filter with UiKit's `tabs` partial (`level: secondary`), the initials with UiKit's `avatar` partial, the table caption with `nowo-task-sr-only`, and no Bootstrap classes (`nav nav-pills`, `nav-link`, `avatar avatar-sm`, `visually-hidden`, `mt-3`).

## [2.4.0] - 2026-11-08

Task API time logs ask a host time-tracking decision (`TaskAccessCheckEvent::ACTION_TRACK_TIME`, `canTrackTime()`) instead of the edit right, and the API principal carries a `channel` (`api` / `mcp`) for the audit. Audited in Appendix II (Pass / Info).

Spec 219 (Beacon's MCP): the API principal names its channel, for the audit. Spec 213, owner decision D-20261108-13 (Beacon): logging time through the Task API follows the time-tracking right, not the task's edit right. A host that does not answer the new decision keeps today's behaviour.

### Added

- **`TaskAccessCheckEvent::ACTION_TRACK_TIME` (`track_time`) and `TaskBoardResourceAccessChecker::canTrackTime()`.** `TASK_ACCESS_CHECK` now carries an action (`getAction()`, `ACTION_VIEW` by default, as before). For `track_time`, `grant()` lets a person who may view the task log time on it without the edit right, and `deny()` refuses it even to an editor; a `track_time` deny sticks against a later listener's `grant()` (for `view` the last call still wins). With no answer, or a grant marked read-only, the edit right decides (`canEditTask()`), so a listener that ignores the action answers as before. Viewing the task is always required.
- **`TaskApiPrincipal::$channel` (spec 219, MCP).** An optional trailing constructor argument, `TaskApiPrincipal::CHANNEL_API` (`api`, the default) or `CHANNEL_MCP` (`mcp`), so a host marks calls from its MCP server; `TaskApiWriteEvent::$channel` carries it to the audit (`API_WRITE`). It must match `[a-z][a-z0-9_]{0,31}` (else `InvalidArgumentException`) and changes no access decision. An authenticator built for 2.2 keeps working and reports `api`. Modelled on WikiBundle 1.24's `WikiApiPrincipal`.
- **`TaskApiGuard::taskToTrackTime()`.** Reach and level as before (*My tasks*: its own tasks only; *Read*: 403 `TOKEN_001`), then `canTrackTime()`.

### Changed

- **`POST /v2/team/{team_id}/time_entries` with a `tid`** asks `canTrackTime()` instead of `canEditTask()`: a project member who may track time but not edit the task is no longer refused 403 `ACCESS_002` before the host's time hook runs, when the host grants `track_time`. Listing time (`GET …/time_entries`, read level) and deleting time (always 403 `TIME_002`) are unchanged; the API has no time update route. The task page's *Log time* form (the bundle's manual entries) still needs the edit right.

### Security

- Audit status: Appendix II (Pass / Low at `d4c61ee`). Fixed since: II-L1 (a `track_time` deny sticks), II-I4 (channel format); documented: II-I1, II-I2, II-I3; tests added: II-I5.

## [2.3.1] - 2026-11-08

Every lock conflict of a board move ends as "changed since" (`stale`) when the move owns the transaction, and the entity manager is closed after a failed apply (audit IC-I9r, IG).

### Fixed

- **Move: every lock conflict is "changed since"** (audit IC-I9r a, b). PostgreSQL's `lock_timeout` (SQLSTATE `55P03`, not mapped by Doctrine DBAL) and a MySQL deadlock raised during the flush (whose `ROLLBACK TO SAVEPOINT` fails with 1305 because InnoDB already rolled back) are answered as `stale` instead of a 500; nothing is written. In the second case the connection is closed to reset DBAL's transaction nesting level. Other driver errors still reach the caller. Only a move that owns the whole transaction maps a lock conflict to `stale`: called inside a transaction the caller opened, the move rolls back its own levels, never closes the connection, and rethrows the driver error, since a MySQL deadlock has also rolled back the caller's earlier writes (audit IG-L1).
- **Move: no half-applied move after a failure** (audit IC-I9r c). Once the move has started to change the entities, any failure closes the entity manager, so a later `flush()` in the same request cannot write part of the move outside the rolled-back transaction. A refusal before that keeps it open. The audit of this release is Appendix IG.

### Documentation

- SECURITY and UPGRADING ("To 2.3.1", "Locks"): the lock conflicts above, and the residuals of audit IC-I8r (a *Fields* document from before 1.28 never synced since stays visible on the target; with sync off and no `detached_task_id` column the source board's erasure does not find a moved task's document).

## [2.3.0] - 2026-11-08

Specs 217 (move and duplicate tasks between boards), 215 (recurring tasks) and 216 FR-013 (why a change happens). **Schema change for every host:** the nullable column `task_board_task_change_history.cause` (see UPGRADING). Opt-in: with `move.enabled: false` and `recurrence.enabled: false` (the defaults) there is no new route, panel section or action, mapping or table, and the pages' output does not change.

### Added

- **`Copy\TaskCopier`** (specs 215 and 217): copies a task with no `Request`, for the duplicate and for a host's worker. Copies title, description, priority, tags, colour, estimate and documents; by option dates, members still assignable and the subtask tree (open, at their depth). Never comments, tracked time, history, dependencies, links, sync rows, completion, archive state or number: one test per item. Asks `QUOTA_CHECK` (`task_create`, every task it creates) before writing, writes in one transaction through `TaskManager::create()` and `TaskMemberAssigner::assign()`, and refuses with `TaskCopyRefusedException`. `preview()` returns what does not carry over and a fingerprint.
- **Move to another board** (`Move\TaskMovePlanner`, `Move\TaskMover`): the task and its whole subtree change board in one transaction, exactly as the plan said (columns by the sync's name rule, completed tasks to the last column, members not assignable and dependencies with tasks that stay removed, a subtask moved alone becomes top-level). Tracked time is never deleted: manual entries take the new board as their snapshot. A synced task is detached for good (its sync item becomes a tombstone), and a move waits for a running sync. `QUOTA_CHECK` gets the new action `task_move` for the open tasks that arrive. New event `TASK_BOARD_CHANGED` (`TaskBoardChangedEvent`, with every task id) after the commit; `TASK_MOVED` is not dispatched.
- **Pages** with `move.enabled`: `task_move_board` and `task_duplicate` (GET choices, POST confirmation with a per-form CSRF token and the preview's fingerprint; a stale preview is shown again with a notice). Both work without JavaScript. The task panel shows *Move to another board* and *Duplicate* only to who may edit the task and its board. `move.targets`: a host's `TaskBoardTargetsInterface` decides the boards offered; any other target answers 404.
- **Recurring tasks** (`recurrence.enabled`, spec 215): `Recurrence\TaskRecurrenceManager` with the series (`task_board_task_recurrences`) and occurrence ledger (`task_board_task_occurrences`, unique per series and key). Fixed dates (days, weeks on weekdays, months on a day or the last day, years; end never, on a date or after N) with an option *next on completion*, or a period after completion. No scheduler: the host asks `dueSeries()` / `dueKey()` or `completionKey()` and calls `createOccurrence()`, idempotent (the row and the copy in one transaction). Older missed dates are recorded, not created. Pause, resume (the resumer becomes the author), skip and undo, stop. A synced task cannot repeat. Occurrences are `TaskCopier` copies of the latest one, as the author, with the context `recurring`. `forgetAuthor()` for account erasure; a series follows its latest occurrence on a move. *Repeat* section in the task panel (block `nowo_task_repeat`), the `task_recurrence` page and actions, and a repeat icon on cards and list rows (one query per board).
- **Why a change happens** (spec 216, FR-013): `Service\TaskChangeContext::run($cause, $write)` writes `$cause` (e.g. `rule:42`) on every history row recorded inside the call (`TaskChangeHistory::getCause()`), and the listeners of the events dispatched inside it read `current()`. `TaskUpdatedEvent` carries the old values (`getPreviousValue()`, `getAddedTags()`, `getRemovedTags()`). `TaskManager::addTag()`, with the task form's rules (a tag of at most 64 characters, at most 20 tags, `TaskConstraints::TAGS_MAX`; audit IC-L3). `history.cause_labeler` (`History\TaskChangeCauseLabelerInterface`) shows the cause in the task's history; without it nothing changes.
- **Audit IC on spec 215.** `createOccurrence()` asks every refusal before its transaction (`TaskCopier::copyChecked()`), so a quota refusal leaves the entity manager open for `block()`, and dispatches the new tasks' events after the commit (IC-I3). A `done:` key whose task is not an occurrence of the series creates nothing (IC-I6).
- `TaskManager::create()` takes an optional `$context`, kept on the "created" history row (spec 215's request).
- **Audit IC fixes (spec 217).** A move needs edit access on every task of the subtree and names the one that blocks it (IC-L1); a duplicate leaves out what the user cannot view. Inside its transaction the move locks the task and re-reads the tree's facts from the database, refusing as `stale` when anything changed (IC-L2). A host's targets are narrowed to boards the user may edit (IC-I1). Items are tombstoned whenever the sync tables exist (IC-I5), and the tombstone keeps the task in `detached_task_id`, so the panel still shows its provider data read-only; the preview names the imported fields that stay on the task, hidden on the target board: the panel, the list search and the Task API show only the fields of a task's own board (IC-I4, owner decision 4). The move locks every task row of the tree before its re-read (IC-L2r), and with sync off it still tombstones the rows of an earlier sync through DBAL (IC-I5). The sync's "Fields" document is hidden on the target board as well and erased with the source board (IC-I8). A deadlock or a lock-wait timeout during a move is the "changed since" refusal, not a 500 (IC-I9). **Schema change with sync tables:** `task_board_sync_items.detached_task_id`. A copy's `TASK_CREATED` and `TASK_MEMBER_ADDED` are dispatched after its commit and dropped when it fails (`Service\TaskEventDeferral`, the dispatcher of `TaskManager` and `TaskMemberAssigner`, which passes events straight on outside a copy; IC-I3).
- `TaskChangeType::BoardMoved`, `Duplicated`, `MemberNotCopied`; `Task::changeBoard()` (internal); `TaskMemberAssigner::canBeAssigned()`.

## [2.2.1] - 2026-11-08

Target: 2.2.1. Audit IB (2026-11-08): Pass (good) / Low at `0d4f804` (Appendix IB; audited at `4539b80`, IB-L1 fixed in `0d4f804`).

### Fixed

- **A column change through the task's edit form is a move.** `TaskManager::update()` changed the column without the completion sync, the reopen limit or the completed / reopened events of `moveToColumn()`: an edit form posting another column (a host template that shows the column field, or a page left open while someone else moved the task) reopened a completed task past the host's limit of active tasks, left a task in the done column open, and fired no `TASK_COMPLETED` / `TASK_REOPENED`. It now asks the limit before anything changes (`TaskReopenRefusedException`: the edit form shows the board's "not moved" flash, logs `task_reopen_limit` and saves nothing of the form), puts the task at the end of its new column, syncs the completion with its history, and fires `TASK_COMPLETED` / `TASK_REOPENED` after `TASK_UPDATED` and `TASK_MOVED`. Callers that move first (the Task API) pass the current column, so nothing fires twice. Import and provider sync do not use this path and keep their rules.
- **A stale task page no longer moves the task back.** The edit form posts the column it was rendered with (`columnIdShown`, new opt-in `TaskFormType` option `shown_column`, on for the edit form); the column changes only when the posted column differs from it, so a page left open while the task moved keeps the task where it is now. A host template that does not post the field (a `form_end` without `render_rest`) gets the posted column, as before.
- **An unknown column is refused.** `TaskManager::update()` with a `columnId` that is not the board's left the task without a column; it now throws `ColumnNotFoundException` before anything changes, and the edit form shows the column's validation error next to the status (it is no longer bubbled to the form, where the task page did not show it).
- **A write of fields never moves a task (audit IB-L1).** `TaskFormData::fromTask()` now sets `keepsColumn`: a null column then keeps the task's own, even none, instead of choosing the first column, so a Task API rename, tag or checklist write of a completed task without a column no longer became a reopening move. Every Task API write that the host's limit refuses after the first check (the limit changed in between) answers 403 `PLAN_002`, not 500. The edit form, which builds its own data, still sends a column-less task to the first column when it posts no column.

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

[Unreleased]: https://github.com/nowo-tech/TaskBoardBundle/compare/v2.17.0...HEAD
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
