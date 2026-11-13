# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [Access control model](#access-control-model)
- [Provider sync: host duties and known limits](#provider-sync-host-duties-and-known-limits)
- [Sync writes by e-mail (fixed in 1.28.0)](#sync-writes-by-e-mail-fixed-in-1280)
- [New board scope step (since 1.30)](#new-board-scope-step-since-130)
- [Unmatched columns (since 1.28.0)](#unmatched-columns-since-1280)
- [Task types, tag colours and descriptions (since 1.28.0)](#task-types-tag-colours-and-descriptions-since-1280)
- [Task numbers and calendar (since 2.1)](#task-numbers-and-calendar-since-21)
- [Move and duplicate (2.3)](#move-and-duplicate-23)
- [Recurring tasks (2.3)](#recurring-tasks-23)
- [UiKit migration (2.0.0)](#uikit-migration-200)
- [Beacon override reduction (2.5)](#beacon-override-reduction-25)
- [Beacon override reduction (2.6)](#beacon-override-reduction-26)
- [Task API (2.2)](#task-api-22)
- [Shared write rules (2.2)](#shared-write-rules-22)
- [Logging and personal data](#logging-and-personal-data)
- [Personal data in synced tasks (RGPD / LOPDGDD)](#personal-data-in-synced-tasks-rgpd--lopdgdd)
- [Dependencies](#dependencies)
- [Reporting](#reporting)
- [Release security checklist](#release-security-checklist)

## Attack surface

| Input | Description |
|-------|-------------|
| **Manage routes** | Authenticated CRUD for boards, columns, tasks, members, and links under `/tools/task-board`. |
| **Task import** | Authenticated file upload (CSV/JSON) on `board_import`; parsed server-side by tagged importers. |
| **Task forms** | Symfony forms for board/task creation and updates (title, description, links, members). |
| **Configuration** | `nowo_task_board` YAML (routes, table prefix, access checker, team resolver). |
| **Provider sync (since 1.23, opt-in)** | Server-side calls to a task provider's API with the host's token, file downloads from the provider's attachment hosts, remote text stored as Markdown. |
| **Task API (since 2.2, opt-in)** | Stateless routes under `api.prefix`, off by default. Token authentication is the host's; `Api\Security\TaskApiRequestListener` and `TaskApiGuard` fail closed. Audit IA: a token whose user the access checker refuses counts as a failed token (L1); with tenancy the guard asks the bundle's tenant resolver too (L2); the security-user check compares ids, not classes, and an `access_control` refusal reads as 403 (I2, I3, documented in [API](API.md)). `TaskManager::archive()` dispatches no domain event, as no UI archive exists; the API's write audit is `API_WRITE` (I5). Writes (2.2, S4): the guard's capability table per write, `API_WRITE` required, the plan limit refused when no `QUOTA_CHECK` listener answers, idempotency keys scoped to the token with hashes only and a replay checked again; the kept answers hold task text for 24 hours (`forget()` on revocation or erasure). Audit IA (2026-11-08): Pass-Low at `637494e`. |
| **TimeTrack bridge** | Optional. When TimeTrack is installed, task provider exposes trackable tasks; access gated by `TaskAccessGuard`. |

## Threats and mitigations

| Threat | Risk | Mitigation |
|--------|------|------------|
| **Unauthorized board access** | Anonymous users open manage UI. | Symfony firewall + `access_control` on `/tools/task-board`; `TaskBoardAccessCheckerInterface`. Keep `security.allow_unauthenticated: false` in production (demo-only bypass). |
| **IDOR on tasks/boards** | User reads or edits another user's board, task, column, link or member by id. | `TaskBoardResourceAccessChecker` on every id-based action: creator, team members, task members and `security.manage_roles` only (403 otherwise, 404 for unknown ids); the index lists accessible boards only. Extend with `BoardAccessCheckEvent` / `TaskAccessCheckEvent` listeners. |
| **Script URLs in task links** | `javascript:` / `data:` URL stored as a link and rendered in `href`. | `Url(protocols: [http, https])` on the link form; render-time `nowo_task_board_safe_url` filter outputs `#` for any other scheme. |
| **CSS injection via column colour** | Arbitrary string rendered inside `style="…"`. | Hex-colour `Regex` on the column form; render-time `nowo_task_board_safe_color` filter drops non-hex values. |
| **Malformed kanban input** | Column id of another board or nested `columnOrder[]` arrays. | `TaskManager::move()` throws `ColumnNotFoundException` (HTTP 400); non-string `columnOrder` entries → HTTP 400. |
| **Unauthorized time tracking** | User tracks time on tasks they cannot access. | `TaskAccessGuard::canTrack()` checks assignee and team membership before TimeTrack provider returns tasks. |
| **XSS in task content** | Malicious HTML in task descriptions. | Twig auto-escaping; rich text via TiptapEditorBundle when configured. |
| **CSRF on forms** | Cross-site form submission. | Symfony CSRF tokens on all manage forms (`form_start` / `form_end`). |
| **CSRF on AJAX / bare POSTs** | Cross-site drag-and-drop move/reorder, advance, priority, remove link/member. | Explicit `isCsrfTokenValid` (fail-closed) on `reorderColumns`, `advanceTask`, `moveTask`, `removeLink`, `removeMember`, `updatePriority`. Twig/`fetch` send `_token` (REQ-SEC-005). Token ids: `task_board_column_reorder`, `task_board_task_move`, `task_board_task_advance`, `task_board_task_priority`, `task_board_task_link_remove`, `task_board_task_member_remove_{memberId}`. |
| **Provider tokens (sync, since 1.23)** | A token in logs, events, the profiler, exceptions, the database or a page. | The bundle never stores a token (the host's `ProviderCredentialResolverInterface` gives one per run). `ProviderCredential` redacts its debug output and refuses serialization; adapters send it only to the provider's fixed API host; provider errors are fixed messages with no chained exception; logs, events, the run history and cursors hold ids, codes and counts only; the HTTP client is the bundle's own (not the profiler-traced `http_client`). A canary-token test checks every one of these. |
| **SSRF through synced files (since 1.23)** | A remote attachment or image URL pointing at internal services. | `RemoteFileFetcher`: https only, the provider's attachment hosts only, no user info / port / IP literal, private and metadata addresses refused after DNS (`NoPrivateNetworkHttpClient`, plus nowo-tech/outbound-url-guard-bundle when installed), redirects followed manually and re-checked, no credential sent, size capped while streaming, type sniffed; SVG, HTML, scripts and executables always refused. |
| **Script in synced text (since 1.23)** | HTML, `javascript:` links or bidi tricks in remote descriptions and comments. | `RemoteContentSanitizer` stores Markdown without raw HTML, keeps only http(s) / mailto link targets, removes controls and bidi overrides and caps the length; the host's `TaskTextRendererInterface` still escapes or sanitizes on output. |
| **Who manages a connection (since 1.23)** | A board editor links, refreshes or unlinks without the right. | `canManageBoardConnection()` (`BOARD_ACCESS_CHECK` with `ACTION_MANAGE_CONNECTION`; default: board creator or `manage_roles`), 404 for others, re-checked for every run (scheduled runs stop when the user loses the right); per-task writes still need `canEditTask()`. Every action is a POST with its own CSRF token. |
| **Mass archive (since 1.23)** | A token that lost access makes a sync archive a whole board. | Only a complete id scan of the same run archives; an empty scan or more than `max_archive_ratio` of the linked tasks is held until a manager confirms; nothing is ever deleted. |
| **Who maps people (since 1.25)** | A user without manage rights reads the people (e-mails) or re-maps them. | Every directory operation and scan needs `canManageBoardConnection()` on **every** board of the directory (`PeopleMappingDeniedException`, routes 404); host routes add CSRF. |
| **Access through a mapping (since 1.25)** | Mapping a remote person to a user who cannot see the board makes them creator or member, and the participant rule grants the task. | Projection rule: `canViewBoard()` before any creator / member / comment author is written; otherwise the person stays a reference. Re-projection only where the user can view the board at execution time. |
| **Privilege by re-mapping (since 1.25)** | Content re-pointed to a teammate who gains authorship and edit rights. | Only an explicit `reassign()` by an authorised actor moves imported content; the target is re-checked as a candidate and viewer per board; `SYNC_PERSON_REASSIGNED` and per-task history. Automatic matching never moves content. |
| **E-mail takeover (since 1.25)** | Someone registers a victim's ClickUp e-mail and gets auto-mapped. | `auto` only with `verified = true` from the host (`require_verified_email`, default true), else `suggested` (human confirmation); candidates scoped by the host; exactly one match; a changed e-mail never re-maps automatically. |
| **Double identity / cross-tenant (since 1.25)** | One remote identity mapped to two users, or one tenant seeing another's people. | One person row per (scope, provider, workspace, remote id) (unique index); `directoryScope()` in the directory key; `candidateById($board, …)` is the only user lookup; reassign touches only the directory's boards. |
| **Silent mapping change (since 1.25)** | Mappings change without trace. | `SYNC_PERSON_MAPPED` on every change (automatic ones with a null actor), reassign / anonymise / review / reproject events; history stores `person:` / `user:` tokens, never names. |
| **Hard delete of a user (since 1.25)** | Deleting a user cascades the boards, tasks, documents and comments they created (with everyone's tasks on their boards). | Since 1.25 (audits FP-M1 / FP-L7) the board, task and document creators and the comment author are `ON DELETE SET NULL` with a name snapshot: no board, task, subtask, document or comment goes with the user. `detachUser()` before the delete detaches them explicitly (and, with `anonymise`, clears the names); erasure order in UPGRADING. Still cascaded: memberships, the user's history entries and their own manual time. A delete without `detachUser()` keeps the name snapshot (FP-I11). |
| **Token scope for time (since 1.25)** | Importing time needs an admin token, which is more powerful. | Every provider call is a `GET` to the constant base URI (`ProviderHttpClient` has no other verb); `assignee` ids come only from the directory; the run stores only `time_mode`. ClickUp tokens have no read-only scope: read-only is a bundle guarantee. |
| **Resource abuse (since 1.25)** | Huge workspaces, endless window splits, mass reassign. | `max_windows_per_run`, window halving down to one hour, assignee chunks, the E5 cap, scans ≤ 15 calls, list search ≤ 40 calls, reassign batches with a cursor. |
| **Remote names and descriptions (since 1.25)** | Controls, bidi or markup in names. | Names are one sanitised line (≤ 120); ids are pattern-checked; time-entry descriptions are plain text (controls and bidi stripped) and passed to the host only, never stored or logged by the bundle. |
| **New provider calls (since 1.27.0)** | More outbound calls with the host's token; a remote answer that makes a run ask without end. | All `GET` through `ProviderHttpClient` to the constant base URI, with pattern-checked ids: `task/{id}/time_in_status` (`sync.status_history.max_calls_per_run`, only for tasks a run wrote; budget kept across slices), `comment/{id}/reply` (`max_comments_per_task`), the archived pass of `list/{id}/task` and `team/{id}/task` (same page caps and paging guard). The token never enters `remote_meta`, the stats or a cursor; cursors are `<n>` / `a<n>`. |
| **Personal data in read-only data (since 1.27.0)** | Names copied into JSON escape `anonymise()`. | Estimates per assignee and `people` custom fields store `person:<id>` (or `ref:<provider user id>` without a directory); names are read from `task_board_sync_people` when shown. Test: the raw columns hold no name, and a forgotten person disappears from the panel. |
| **Remote custom fields (since 1.27.0)** | Script, `javascript:` links, huge values or forged types. | `ClickUpCustomFieldParser`: types checked, text sanitised and cut, only http(s) URLs, valid e-mails without `?` or `&`, finite numbers, hex colours; choices capped; what does not fit is kept as text. A misfit never redefines the board's field, and `SyncFieldViewProvider` and the template re-check a URL before drawing a link (audit GW-L1). Rendered escaped; the `mailto:` value is URL-encoded. E-mail, phone and text values are personal data when they name someone: see [below](#personal-data-in-synced-tasks-rgpd--lopdgdd). |
| **Files of comments (since 1.27.0)** | A copied file that erasure does not know stays in the host's storage. | Tracked in `task_board_sync_attachments` (key `comment:<hash>:<hash>`), so `TaskErasedEvent::$attachmentRefs` names it; reused while unchanged; replaced and removed copies are deleted; nothing is downloaded for a comment in conflict; same `RemoteFileFetcher` allow-list (audit GW-M2). |
| **Lost work and rate limits (since 1.27.0)** | A provider error, or a token that may not read an endpoint, burns requests or silently skips tasks. | A failed page defers every task not finished (GW-L2, also in 1.26.3). Budgets: `status_history.max_calls_per_run`, `comments.max_reply_calls_per_run`, `watchers.max_detail_calls_per_run`; a 403 on time in status stops those requests for the run. What was not read is reported as such. |
| **Remote keys in stats (since 1.27.0)** | An answer with thousands of keys grows the stored report, or injects markup into it. | Keys must match `[a-z0-9_]{1,40}`; at most 200 keys per run (`SyncTaskDetailsWriter::MAX_KEYS`); rendered escaped; values are never stored, only counts. |
| **People page (since 1.27.0)** | A viewer reads remote e-mails, or re-maps someone. | `canManageBoardConnection` on every board of the directory (404 otherwise), CSRF on save, candidates only from the host's resolver, a same-name candidate is only suggested (a remote person chooses their name; GW-L3), a row absent from the POST is left alone (GW-L4), `SYNC_PEOPLE_MAPPING_CHANGED` with ids only; a mapping grants no access (projection rule). |
| **Malicious import files** | Oversized or crafted CSV/JSON uploads. | Authenticated route only; Symfony file upload limits; parsers validate structure; import runs in orchestrator with explicit source selection. |

## Access control model

1. **Route level** — `TaskBoardAccessCheckerInterface` (default: role-based via `ConfigurableTaskBoardAccessChecker`).
2. **Resource level** — `TaskBoardResourceAccessChecker` (board creator, team members, task members, `security.manage_roles`; toggle with `security.resource_scoping`), overridable by `BOARD_LIST_QUERY`, `BOARD_ACCESS_CHECK` and `TASK_ACCESS_CHECK` listeners.
3. **Managing columns** (since 2.5.0) — `canManageColumns()`: `canEditBoard()` plus the host's `BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS` decision (granted by default). Every path that changes a board's columns asks it: the seven column and column-set routes, the Task API status paths, a file import that would add columns, a provider sync (its actor) and `updateStatusColumnMap()` (no actor: no column). `deny()` sticks against a later `grant()`; `markReadOnly()` on the event refuses it too. Controls, the reorder URL and its token are not rendered when it is refused. Not column management, by design (audit IM-I5): creating a board (its default columns or a column set: `canCreateBoard()` and the quota) and emptying or deleting a board (`ACTION_MANAGE`). A listener that denies unknown actions denies this one too (see UPGRADING, To 2.5.0).
4. **Logging time through the Task API** (since 2.4.0) — `canTrackTime()`: view access plus the host's `TaskAccessCheckEvent::ACTION_TRACK_TIME` decision; with no answer (or a grant marked read-only), the edit right, as before. `deny()` refuses even an editor and sticks against a later `grant()`.
5. **Time tracking** — when TimeTrack is installed, `TaskAccessGuard` validates assignee or team membership before exposing tasks to TimeTrack.

## Provider sync: host duties and known limits

Since 1.23 (audit Appendix EG). What the host must do, and what the bundle does not do:

- **Render remote Markdown safely.** `RemoteContentSanitizer` empties every link target that is not http, https or
  mailto and removes raw HTML outside code (EG-L3), but it is the second line of defence. A host rendering task text
  with league/commonmark must set `allow_unsafe_links: false` (and `html_input: escape` or `strip`), or sanitize the
  HTML afterwards (symfony/html-sanitizer).
- **Serve stored files safely (EG-I2).** `TaskAttachmentStorageInterface::store()` gets the sniffed MIME type, which
  depends on libmagic: HTML after a long text prefix can be sniffed as `text/plain`. Serve stored files with the stored
  type, `X-Content-Type-Options: nosniff`, and `Content-Disposition: attachment` for anything that is not an image.
- **Keep URLs out of logs (EG-L4).** The bundle's own HTTP clients get no logger. A client given through
  `sync.http.client` must not log URLs either (signed attachment URLs carry their signature in the query).
- **Long pages and the lock (EG-I4).** The run's heartbeat is written between pages. A page of 100 tasks with many
  large files can exceed the 10-minute stale window; a concurrent run may then take the lock (the database keys still
  prevent duplicates). Keep `sync.attachments.max_bytes_per_run` and the time slice (`inline_slice_pages`) modest.
- **The actor's rights (EG-I5).** `canManageBoardConnection()` checks the board creator and `security.manage_roles`
  through the **session** user's roles. A custom `SyncActorResolverInterface` that returns another user than the one
  clicking inherits the clicking user's role check; per-task writes still require `canEditTask()` for the actor.
  Worker runs (no session) only pass for the board creator.
- **Outbound guard settings (EG-I6).** `OutboundUrlGuardAdapter` builds `OutboundUrlGuard` with its own (strict)
  defaults, not the host's configured guard service: host allow / deny lists of that bundle do not apply to synced files.
- **Candidates without a resolver (FP-I1).** Without `sync.people.resolver` and without `assignee_provider`,
  `DefaultSyncPersonResolver::isCandidate()` is true for every user and `candidateById()` finds any user id: a board
  manager can map a remote person, and so route imported time through the sink, to any account of the host.
  Multi-tenant hosts must set `sync.people.resolver` (or at least `assignee_provider`).
- **Time and auto-matching (FP-I2).** Only people mapped to a user reach the time sink. Auto-matching trusts the
  provider's (validated, ASCII) e-mail: a linked workspace's admin can attribute time to a verified candidate of the
  board. Hosts that want time only for human decisions accept only `manual` mappings in their sink.
- **Listeners that grant everything (FP-I3).** A `BOARD_ACCESS_CHECK` listener that calls `grant()` without reading
  `getAction()` also grants `manage` (empty or delete a board, since 1.25) and `import_tasks`. With scoping off,
  `manage` defaults to every editor. Check the action.
- **The eraser and the connection (FP-I4).** `TaskBoardEraser` unlinks the board after `canManageBoard()` (`manage`)
  only, without asking `manage_connection`: the board goes (or is emptied) anyway. Deny `manage` to whoever must not
  drop the link that way.
- **CLI imports are operator-trusted (FP-I5).** `nowo:task-board:import` runs as the console operator: it asks neither
  `import_tasks` nor `canEditBoard()`. Do not expose it to untrusted callers (e.g. through a web-triggered process).
- **Shared lookup cache (FP-I6).** The list-search cache is keyed by `connectionRef`; keep that unique per stored
  token across tenants that share `sync.lookup.cache_pool`.
- **The erase cursor (FP-L3, FP-I9, FP-I10).** It is signed (HMAC with a key derived from `kernel.secret`) and bound
  to the board, the actor, the operation and its run (run id, issue time, TTL); still keep it server-side (a
  Messenger message), never in a browser round trip.
- **Erase-cursor residuals (FP-I13).** The TTL counts from the issuing node's clock (a node whose clock runs ahead
  extends it by the skew; keep worker clocks in sync). Within the TTL, an unfinished run resumed after new tasks were
  added erases them too (`canManageBoard()` is re-checked on every call, so never more than a fresh run by the same
  actor could). Two copies of one cursor resumed in parallel may undercount, or dispatch `BOARD_EMPTIED` /
  `BOARD_DELETED` twice: de-duplicate `BoardErasedEvent` by its `runId`.
- **Progress polling (EG-I7).** `GET …/connection/progress/{runId}` advances an inline run by one slice (contract §6.1).
  It needs the unguessable run id and the manage right; it is not a practical CSRF target, but it is a GET with an
  effect.

## Sync writes by e-mail (fixed in 1.28.0)

A sync that has no people session or no person reference for a row (`SyncOptions::people` false, no directory attached, a row or comment with an e-mail but no valid id) matches users by e-mail or name. From 1.23.0 to 1.27.0 that path wrote the match as task member and comment author without asking whether the user could view the board, and a member row gives the task through `isTaskParticipant`. Since 1.28.0 it writes only a user who can view the board and is not attribution only (audit HI-M2; `emailPath.notWritten` counts the others). Until a host upgrades: make `TaskAssigneeProviderInterface::canBeAssigned()` refuse users without board access, have the import user resolver return null for them, and deny non-members through `TASK_ACCESS_CHECK`. No advisory: it needs a provider anomaly or a host's own setting, and the match is on the host's own users. The answer is kept per user for one slice, so a permission removed during a slice applies from the next.

## New board scope step (since 1.30)

Any scope offers New board on the index without asking `canCreateBoard()`. A provider returns `[]` on a tenant's page where the user may not create, so that the check decides the button and the create route ([CONFIGURATION](CONFIGURATION.md#new-board-since-130)). `[]` is not a denial: with a checker that allows the tenant (the default), the button shows and the create route creates there without calling `resolve()`. Access stays the checker's. A button is not a permission: the create route still checks the tenant. 2.1.1 documents this and changes no check. Audit HZ (2026-11-07): Pass (good) / Low.

The scope and its fields come from the browser. A select refuses a value it did not offer, `BoardScopeProviderInterface::resolve()` must check permissions again, and the bundle then asks `canCreateBoard()` for the resolved tenant. Refusals share one message, so they do not say whether a tenant exists. Slugs are unique per tenant so that a suffix tells nothing about another tenant. A board icon is validated as one emoji and printed escaped, marked decorative. A Markdown description goes through the host's text renderer, which must escape what the user typed; the output is printed raw, as for task text. A board with no tenant has no database check on its slug: NULL is distinct in a unique index, so two creates at the same moment can store the same slug. The bundle routes by id, so nothing breaks in it; a host reading by slug alone should not rely on one row.

## Unmatched columns (since 1.28.0)

`unmatchedColumns: delete` deletes board structure from a background run. It checks `canEditBoard` for the run's actor at that moment. The empty check and the delete share a transaction under `SELECT … FOR UPDATE` on the column row, because `task.column_id` is `ON DELETE SET NULL`: a task written at the same time waits, then is seen or refused. Audit HI, verified on MySQL and PostgreSQL with a second connection, including a stress run with no task left without a column.

## Task types, tag colours and descriptions (since 1.28.0)

Remote text: type and list names are single clean lines, escaped by Twig. A list's description is sanitized Markdown stored on the connection and printed as escaped text. A tag colour reaches the page through `data-nowo-task-vars`, like column colours, and is checked as plain hex (`#` and 3 to 8 hex digits) in the adapter and again when read back from `remote_meta`. A task mention builds its address only from an id matching the adapter's id pattern.

## Task numbers and calendar (since 2.1)

Both opt-in.

| Area | What it does |
| --- | --- |
| `task_by_number` | A number names a task only inside the tenant of the URL, or `?board=` after the board's own checks. The task then passes `canViewTask()`. Unknown, retired, unreadable and bad input answer the same 404, so a number's existence does not leak |
| Alias across tenants | The redirect needs `canViewTask()` and the tenant resolver accepting the task's tenant for this user. Otherwise 404 |
| `task_schedule` | CSRF (`task_board_task_schedule`), then 404 out of tenant or unreadable, 403 without `canEditTask()`. Both dates are required, so a partial request never clears one; dates are strict `Y-m-d` in years 1970 to 2200, with start not after due |
| `TaskNumberLedger` | No access check: hosts check what `find()` and `findInScopes()` return. `purgeScope()` deletes a scope's numbers, is the host's to call on a tenant that is gone for good, and refuses while a task still carries a number there |
| Second connection | A reservation inside a caller's transaction opens a short-lived connection with the same parameters, and writes only the counter. If that fails, it falls back to the caller's transaction: a gap at most, never a duplicate |
| Calendar page | Reads only tasks of a board the user may view. URL filters are checked against what the board offers. Task titles reach the page through Twig escaping and the client redraws from the server's HTML (`DOMParser`, `importNode`) of its own same-origin page |

## Move and duplicate (2.3)

Off unless `move.enabled: true`. Audited for 2.3.0 (Appendix IC, Pass/Info).

| Threat | Mitigation |
|---|---|
| Moving or copying into a board the user may not edit | The target must be one of `TaskBoardTargetsInterface::targets()` (default: `canEditBoard()` in the task's tenant), any other answers 404; the source needs `canEditTask()` and `canEditBoard()`, else 403; a task the user cannot see answers 404 |
| A forged confirmation | Per-form CSRF tokens (`task_board_task_move_board`, `task_board_task_duplicate`); the GET choices write nothing |
| Confirming a preview that no longer holds | The POST carries the preview's fingerprint; when the facts changed, nothing is written and the new preview is shown |
| Plan limits skipped by a new write path | A duplicate asks `QUOTA_CHECK` `task_create` with every task it creates, a move `task_move` with the open tasks that arrive, before writing. A worker calling `TaskCopier` with no listener must ask its own limits |
| Tracked time lost | A move never deletes a time entry: manual entries stay on their task with a new board snapshot; TimeTrack's rows point at the task id. A copy never copies time |
| A partial move | One transaction: a failure changes no board, member or dependency (`TaskMoverTest`) |
| A subtask moved or copied beyond the user's rights (audit IC-L1) | A move needs `canEditTask()` on every task of the subtree, else it is refused (a hidden task is not named). A duplicate leaves out what the user cannot view, with its subtasks, and the preview counts it |
| A write between the plan and the move (audit IC-L2) | Inside the transaction every task row of the tree is locked (`SELECT … FOR UPDATE`, a level at a time; audit IC-L2r), so a concurrent dependency, member or subtask on any of them waits for the commit, and the tree's facts (tasks, completion, archive, every dependency and member touching them) are read again from the database; any change refuses the move as `stale`, rolled back with the entity manager still usable |
| A host's targets offering too much (audit IC-I1) | The answer of `move.targets` is narrowed to active boards passing `canEditBoard()`. Not to the tenant: spec 217 lets a host offer other tenants. The source gate is `canEditTask` + `canEditBoard`, as spec 217 decides, not the board's manage decision |
| Members on the target (audit IC-I2) | Kept or copied when `canBeAssigned()` allows. Without an assignee provider every user may be assigned, so every member stays, as anywhere in the bundle |
| Listeners acting on a rolled-back copy (audit IC-I3) | The task events of a copy wait for its commit (`TaskEventDeferral`) and are dropped if it fails |
| A deadlock or lock wait during a move (audit IC-I9, IC-I9r) | Every lock conflict is answered as `stale`, the same "changed since" refusal, after the rollback: nothing was written. That covers Doctrine's deadlock and lock-wait-timeout exceptions (MySQL 1213 / 1205, PostgreSQL 40001 / 40P01, SQLite "locked"), PostgreSQL's `lock_timeout` (SQLSTATE `55P03`, which Doctrine DBAL does not map, so the mover checks it; since 2.3.1), and a MySQL deadlock raised during the flush: InnoDB has already rolled the whole transaction back, so the flush's `ROLLBACK TO SAVEPOINT` fails with 1305 and that error replaces the deadlock; a 1305 on a `SAVEPOINT` (the server ended the transaction, normally by rolling it back; host code that commits implicitly inside the flush would also end it, audit IG-I1) is answered as `stale` too, and the connection is closed to reset DBAL's nesting level (the next query reconnects; since 2.3.1). Only a move that owns the whole transaction does this: called inside a transaction the caller opened, the move rolls back its own levels, never closes the connection and rethrows the driver error, since a MySQL deadlock has rolled back the caller's earlier writes too (audit IG-L1). Any other 1305 (for example a missing function) and every other error still reach the caller. Once the move has started to change the entities (`apply()`), any failure closes the entity manager, as Doctrine does when the flush fails, so a later `flush()` in the same request throws instead of writing a half-applied move outside the transaction (since 2.3.1; the next request has a new entity manager). A failure before that (the row locks, the re-read) leaves it open, so the page can show the new preview (`MoveLockFailureTest`). PostgreSQL has no `lock_timeout` by default, so a move then waits on a concurrent write of its tree until that write ends (its deadlock detection still applies). 2.3.1: audited in Appendix IG (Pass / Low, IG-L1 fixed) |
| The imported values as a document (audit IC-I8) | In the sync's `text` and `both` modes the "Fields" document holds the same values as text. A move records the board they came from on it: the document is hidden on the target, kept, and reached by the source board's `eraseBoard()` / `eraseByValue()` only. Residuals (audit IC-I8r, Info): a *Fields* document written before 1.28 and never synced since has no `syncFields` mark, so a move does not record its origin: it stays visible on the target, and the target's `eraseBoard()` can reach it (the sync marks such documents on its next run). With sync off and no `detached_task_id` column (an earlier sync's table, not migrated; IC-I5's DBAL path), the tombstone does not remember the task, so the source board's `eraseBoard()` / `eraseByValue()` no longer find the moved task's document, even once sync is back on; a global `eraseByValue()` (`board = null`) still does. Hosts that keep an earlier sync's table add the column before moving (UPGRADING, To 2.3.0, item 5) |
| Move and plan limits (audit IC-I7) | `QUOTA_CHECK` `task_move` is new: a listener that knows only the older actions lets it through. Safe while `move.targets` stays within one payer (spec 217, FR-011); a host whose targets cross payers handles `task_move`, and a host that limits by route needs no entry for the move route only while that holds |
| A sync turned back on (audit IC-I5) | Items are tombstoned whenever they exist: through the ORM while the sync tables are mapped, through DBAL when sync is off but the table is there (with `detached_task_id` when that column exists). The preview counts them either way |
| A sync rewriting a moved task | The sync item becomes a tombstone, so a later run neither updates nor recreates it; a move is refused while a run holds either board |
| Personal data in copies | A copy is an ordinary task of its creator, with members added through the assigner: the account erasure that reaches the source reaches it. Member names left out are history rows like any member change |
| Information about other boards | The preview lists only titles of tasks on the source board (which the user may edit) and the names of members being removed |
| Provider data of a moved task (audit IC-I4) | Stays on the tombstone item, which keeps the task in `detached_task_id`: the panel shows it read-only and the people directory tools erase it there. Imported field values stay on the task under the source board's fields, hidden on the target board (the panel, the list search and the Task API read only the task's own board's fields), so `eraseBoard(source)` and `eraseByValue(…, source)` still reach them; the *Fields* document moves with the task and is reached through the target board or `null` |

## Recurring tasks (2.3)

Off unless `recurrence.enabled: true`. Audited for 2.3.0 (Appendix IC, Pass/Info).

| Threat | Mitigation |
|---|---|
| Changing a series without the right | The page and every action need `canEditTask()`; an unseen task answers 404, a read-only one 403; each action has its own CSRF token |
| A task participant as author (audit IC-I6) | Spec 215 lets whoever may edit the task make it recurring, so a participant without `canEditBoard()` can become an author. The host re-checks the author's `canEditBoard()` before each `createOccurrence()` (spec 215, FR-014) and pauses the series otherwise. Keys come from `dueKey()` and `completionKey()` only; a `done:` key whose task is not an occurrence of the series creates nothing |
| A worker creating tasks nobody may create | No scheduler in the bundle: the host checks its plan and the author's access before `createOccurrence()`; the copier still asks `QUOTA_CHECK` and refuses before writing. An author erased or gone pauses the series (`forgetAuthor()`, or no author at run time) |
| Duplicate occurrences | One row per series and key, unique, written with the task in one transaction; retries, re-runs and redeliveries create nothing more. A lost race returns null after Doctrine closes the entity manager: the worker resets it |
| A refusal leaving the worker unusable, listeners acting too early (audit IC-I3) | Every refusal of `createOccurrence()`, the host's quota included, comes before its transaction, so `block()` still works; the new tasks' events wait for the commit and are dropped if it fails |
| A flood after downtime | Only the latest missed date is created; older ones are recorded as missed |
| Copying what must not travel | The `TaskCopier` rules: never comments, time, history, dependencies, links, sync rows or the number; members only while assignable |
| Personal data | The author's name is a snapshot; `forgetAuthor()` clears it and the author; the user's deletion sets `author_id` to null |
| A synced task rewritten by its provider | Refused (`synced_task`) |

**Change causes (spec 216, FR-013).** The cause is the host's text, set only by code around a write, never from a request. It is shown only through the host's labeler, escaped by Twig. It is not personal data by design: a host stores an id (`rule:42`), not a name. `TaskManager::addTag()` applies the task form's tag rules (64 characters, 20 tags) and throws otherwise (audit IC-L3), so a rule cannot write what the form refuses. `TaskChangeContext` is reset between the requests of a worker and popped in `finally`, so a failed write never leaks its cause into the next one.

## Beacon override reduction (2.5)

| Threat | Mitigation |
|---|---|
| The redirect of a moved task's old address telling that a task exists (TB-14) | Only a `GET` of a full page whose task is in another tenant, for a user who passes `canViewTask()` and may open that tenant (`tenantOfKey()`), answers 302. Everyone else, a task outside any tenant, a panel fetch and a `POST` keep the same 404 as an unknown id |
| A host action of the New board form (TB-15) | `action` is the host's template value, not request input; the CSRF token, the create check and the quota still run on the route that receives it |
| A host close attribute printed into the dialog footer (TB-15) | `close_attribute` must match `data-[a-z0-9-]+` to the end of the string (no trailing line break), else the kit's `data-nowo-ui-dialog-close` is used (no `on*` handler) |
| The progress URL of the strip (TB-16) | Given only to whoever manages the connection (`progressUrl` as before), and polled only with `sync.badge.progress` (audit IM-I11); readers get an indeterminate bar. The meter's attributes pass UiKit's `nowo_ui_safe_attrs()` |
| A help link URL from a host service (TB-17) | The `url_resolver` result goes through the same template guard as `url` (http(s) or a path on this site, no white space, control characters or backslashes; anything else renders `#`), after the `access_attribute` check |

## Beacon override reduction (2.6)

Audit status: Appendix IT (batch, 2026-11-08) **Pass**; IT-I1..I6 Info (IT-I1/I2 documented in UPGRADING).

| Threat | Mitigation |
|---|---|
| `data-nowo-task-can-edit` claiming an edit the user may not make (TB-19) | Printed from `canEditTask()`, the same decision the edit route checks (403 otherwise); `"true"` only when that value is exactly `true`, so a host template without it prints `"false"`. It drives display only: no write trusts it |
| A tenant index opening a board the user may not see (TB-18) | The per-path switch only chooses whether the index redirects; the board is one of `filterBoards()` for that user and tenant, exactly as the global switch, and the first board still needs the create check and the quota |
| A path setting picked from request input (TB-18) | The setting is chosen by the matched route name (`<name_prefix><routes.index.name>`), set by the router from configuration, not by the slug or the query |

## UiKit migration (2.0.0)

- **Quota event.** `TaskBoardEvents::QUOTA_CHECK` is a host hook, not a permission. The paths that do not ask are listed in [USAGE](USAGE.md#host-hooks-since-200). An import's count is taken before it writes, inside its transaction.
- **First board on a GET.** `first_board_provisioner` runs only for a user the create check and the quota accept. A provisioner writes on a GET: it must be idempotent, as a repeated visit finds a board and does not call it again. Two visits at the same moment can both find none: a host's provisioner handles that.
- **Tenant list check.** A refusal with a tenant answers 404, so the tenant's existence is not told apart.
- **Help link.** `import.help_link` keeps the URL guard of 1.22.1 for `url`. `access_attribute` needs `security.authorization_checker`; without it the link is not shown.
- **Runtime confirm.** `window.confirm` stays only when UiKit's confirm script is missing, so a destructive action never goes unasked.
- **Avatars.** Pictures now pass UiKit's `AvatarUrlPolicy`: https without userinfo, or a same-origin path.
- **Subtask nodes.** Titles reach `tree` as text, escaped by the partial, and in `data-*` attributes through `nowo_ui_safe_attrs`.

## Task API (2.2)

Off unless `api.enabled: true`. Audit IA (2026-11-08): Pass-Low at `637494e`: independent audit IA (2026-11-07 and 08) covered the skeleton, reads, writes, files, time, management, the contract test and the docs; every finding was fixed or documented.

| Threat | Mitigation |
|---|---|
| A host widening a token by mistake | `TaskApiWhere::everything()` grants the person's whole reach: a host maps only a token's explicit "everything I can reach" to it, never an empty, missing or unreadable scope (`limitedTo([], [])`). Where only narrows; access comes from the checkers |
| A token reaching more than its person | Level ∩ the person's access ∩ where, per request, in `TaskApiGuard`; the access checkers judge the security user, which must be the token's user (else 500). Out of reach is always the same 404 |
| A token reaching another tenant | With tenancy, the bundle's tenant resolver is asked for each board's tenant, whatever the workspace hook answers |
| Brute-forcing tokens | Failed tokens per IP (`registerFailedToken`), including a valid token whose user is refused; the token's own rate limit |
| A credential in logs | Read from `Authorization` only; never logged or echoed; errors log the exception class only |
| Writes skipping the UI's rules | The UI's services and the forms' constraints (`TaskConstraints`); the plan limit refused when no `QUOTA_CHECK` listener answers; an unaudited write refused (503) |
| A retry writing twice, or a replay leaking | Idempotency keys per token, hashed; the request (method, path, query, body, upload bytes, name and type) fingerprinted; refusals not kept; a replay re-checks the token and the reach |
| Tracked time lost | Deleting tasks and boards keeps time, detached; deleting time is never offered (403) |
| Telling MCP calls apart in the audit (2.4.0, spec 219) | `TaskApiPrincipal::$channel` (`api` default, `mcp` set by the host's authenticator) reaches `TaskApiWriteEvent::$channel`. It is a label for the audit only, set by the host from the call path (never from client input), limited to `[a-z][a-z0-9_]{0,31}`, and grants nothing. Appendix II |
| Time logged by someone who may not (2.4.0) | `POST …/time_entries` with a `tid` asks `canTrackTime()`: view access, the token's level (My tasks: own tasks only), then the host's `track_time` decision, else the edit right. A listener blind to the action answers it as it answers viewing, so the old result holds; only a listener that grants `track_time` on purpose widens it, to time logging only. A `track_time` deny sticks against a later grant (II-L1). The grant ignores a read-only board and is cancelled by any read-only mark (II-I1, II-I2). The task page's *Log time* form still uses the edit right, and the TimeTrack bridge its own rule (II-I3). Appendix II |
| Provider data | Provider connections are never reachable; linked tasks and boards are not deleted; stored files of an erased task are named to the host |
| Raw task text | Returned as stored: an API reader sees the alt text and id of an embedded host image the UI hides; the file stays behind the host's download check |

Known and documented: a multi-field update is not one transaction (refusals are checked first); a narrow race between a restore and a move can answer 500 after the restore; concurrent board deletes may dispatch `TASK_ERASED` and `BOARD_DELETED` twice; `forget()` clears one token's kept answers.

## Shared write rules (2.2)

Spec 213 gives the comment rules, the restore limit and the field constraints one home (`TaskCommentPolicy`, `TaskManager::restore()`, `TaskConstraints`), so a write path other than the forms cannot apply different ones. `TaskBoardEraser::eraseTask()` asks the board's manage decision itself, erases leaf-first so the `PreRemove` that keeps time runs for every subtask (`parent_id` is `ON DELETE CASCADE`, which would skip it), and refuses a task that follows a provider. After an unlink, the files a sync stored for the subtree are named in `TaskErasedEvent::$attachmentRefs` and their rows removed, on every database (audit IA-M1): the host deletes them, as for `deleteBoard()`. `tests/Functional/Characterization` pins the UI's output.

**Edit form column change (2.2.1; Audit IB (2026-11-08): Pass (good) / Low at `0d4f804` (Appendix IB; audited at `4539b80`, IB-L1 fixed in `0d4f804`)).** `TaskManager::update()` moved a task to the column the edit form posted without the reopen limit, the completion sync or the completed / reopened events of `moveToColumn()`, so a posted column reopened a completed task past the host's plan limit (bug class: a limit hook skipped by another write path). It now shares `moveToColumn()`'s limit check, before any field changes, and its completion and events (`TaskEditFormColumnTest`, `TaskEventsTest`). The form posts the column its page showed (`columnIdShown`), so a stale page cannot move a task back, and an unknown column is refused rather than clearing the column. A write of fields from `TaskFormData::fromTask()` never moves the task, even one without a column, and the Task API maps every limit refusal to 403 `PLAN_002` (audit IB-L1). Write paths of a task's column: board drag and the column buttons (`move()`, `moveTo*()`), the edit form and the Task API's field update (`update()`, the API passing the column its `moveToColumn()` left), the Task API's status and checklist items (`moveToColumn()`, `moveToDone()`), column delete (`BoardColumnManager::delete()`, own limit check), import (`ImportedTaskUpdater`, no limit by design) and provider sync (`SyncTaskWriter` / `SyncChecklistWriter`, own limit checks and provider completion).

## Logging and personal data

REQ-OBS-001 (since 1.21): every successful board / task write logs one `info` line, an invalid CSRF token one `warning`,
and every import its start, result and failure ([CONFIGURATION — Logging](CONFIGURATION.md#logging-since-121)). The
context holds only the action, the ids of the board objects (UUIDs) and counts. The bundle never logs user ids, names,
e-mails, task titles or descriptions, comment bodies, link URLs, uploaded file names or contents, nor tokens.
Since 1.25 the never-logged list also covers the sync's people: person names and e-mails, provider user ids together
with names, and time-entry descriptions (logs and events carry directory, person and board ids and counts only). Names
and e-mails of remote people live only in `task_board_sync_people` (cleared by `anonymise()`); no avatar URL is stored.
Since 1.27.0 `task_board_sync_items.remote_meta` and `people` custom fields keep references to those rows, not names. The
exports `exportFor()` / `exportDirectory()` list the people, not their estimates per task. There is
no `dump()`, `var_dump()` or `error_log()` in `src/`.

## Personal data in synced tasks (RGPD / LOPDGDD)

An auditor's reading for owners and counsel (audit GW, 2026-11-06), not legal advice.

**AI security audit (REQ-SEC-004), latest: Appendix NN (2026-11-12), 2.14.0 (calendar day annotations, audited at `7cc4f1b`): Pass, no High or Medium, no advisory; follow-ups L1, L2 and I1..I4 went to 2.14.1.** Appendix NI (2026-11-12), 2.13.0 (multi-board task list, immediate audit of permissions and export): NI-1..NI-7 fixed before release, no out-of-scope board leaks. Appendix KO (2026-11-10), 2.11.0: OK to tag, Info only. Appendix JY (2026-11-10), 2.10.0 and 2.10.1: OK to tag, JY-L1..L3 fixed. 2.15.0 adds framework-free host ports and event accessors with no new route, template, upload or outbound call and has no separate audit appendix. Grade recorded in the Nowo monorepo `BUNDLES_SECURITY_ANALYSIS.md`.

Earlier audits: 2.2.1: Audit IB (2026-11-08): Pass (good) / Low at `0d4f804` (Appendix IB; audited at `4539b80`, IB-L1 fixed in `0d4f804`). Pass (good) / Low at `4ac4848` (Appendix HZ, 2026-11-07), for 2.1.1.** 2.1.0: Pass (good) / Low at `3c105a1` (Appendix HY). 2.0.2: Pass (good) / Low at `a510f4f` (Appendix HY). 2.0.1: Pass (good) / Low at `f2fa0d4` (Appendix HY). 2.0.0: Pass (good) / Low at `08e51a4` (Appendix HX).

**Default, for the owner to see: everything is imported.** That includes e-mail, phone and free-text custom fields,
estimates per assignee and archived tasks. `sync.custom_fields.exclude_types` and `sync.import_archived: false` reduce it
(Art. 5(1)(c) and 25 RGPD). Which default suits a deployment is the host's decision.

What the bundle does and does not do:

| Duty | State |
| --- | --- |
| Erasure, Art. 17 and 19 RGPD; Art. 15 LOPDGDD | `SyncFieldValueEraser::eraseByValue()` / `eraseBoard()`, called by the host, which authorises the caller; `eraseByValue()` with a null board crosses every tenant, so a multi-tenant host passes the board. An erased value does not return on a re-sync while the provider has the same one; a different one is imported, also for `people` and `checkbox` values. Clear it at the source too. A suppression record stays in the row: an HMAC with a subkey of `kernel.secret`, over the stored value. Without the secret it cannot be tested against guesses; with the secret it can, so it may still be personal data (GWd-L2). Changing `kernel.secret` makes erased values importable again if the provider still has them: a host that must keep a value out keeps its erasure log and runs it again after rotating. Since 1.28.0 the eraser also removes the lines of the *Fields* document in `text` and `both` modes and `exportByValue()` / `exportBoard()` read them (see the residual below). |
| Blocking, Art. 32 LOPDGDD | The bundle has no blocking state. Erasure in Spain implies blocking for the limitation periods: the host keeps this outside and defines its policy. |
| Access and portability, Art. 15 and 20 RGPD; Art. 13 and 17 LOPDGDD | `SyncFieldValueEraser::exportByValue()` / `exportBoard()` for field values, `TaskSyncPersonRepository::exportPerson()` for one person's row, estimates, comment assignments and people fields (GWd), `exportFor()` / `exportDirectory()` for people. Since 1.28.0 `exportPerson()` also names the comments they wrote, the tasks they created and the tasks they are assigned to or watch, by id. **Not in any export:** comment bodies and task text; the host adds them if it must. |
| Minimisation and by default, Art. 5(1)(c) and 25 RGPD | See the default above. |
| Storage limitation, Art. 5(1)(e) RGPD | After an unlink, tasks, their field values and the rows that track stored files stay; a later deletion of the task or board names the files (GWd-M1). Run `eraseBoard()` when the host's retention rule asks for it. |
| Information, Art. 14 RGPD; Art. 11 LOPDGDD | Data arrives indirectly from the provider. The people concerned, who may be customers and not staff, must be informed within a month or an exception documented. Host side. |
| Roles and records, Art. 28 and 30 RGPD | The host is usually a processor for its tenants, and the import is a processing activity to record. Host side. |
| Workplace, Art. 87 LOPDGDD and Art. 20 bis ET | Estimates and imported time per person can serve to monitor workers. Hosts must say so and limit their use. For counsel. |

What `anonymise()` is: **pseudonymisation**. Name and e-mail go from the person row, so every view that reads references
shows *Former user*. `provider_user_id`, the remote role, dates and `person:` links stay, so someone with access to the
provider can re-identify the person. It does **not** reach field values other than `people`, comment bodies and
`@mentions`, task titles and descriptions, the *Fields* document of `text` and `both` modes, or files. The name on a manual time
entry is cleared with `detachUser(..., anonymise: true)`, see below.

Pruning a directory deletes a person referenced only from JSON (an estimate or a people field); views then show `—`.

**Tracked time (owner rule).** Deleting a task, a board **or a user** keeps all manual time. An entry whose user is gone keeps
the name it was logged with (`user_name`) until it is forgotten: `detachUser(..., anonymise: true)`,
`TaskTimeEntrySnapshotPurger::detachUser($user, true)` or `forgetUserName($name)`. That name is the user's label, which is their e-mail when they have no display name. The entry's own description is free text and stays; so does the task title snapshot unless it is purged. An entry is removed only by its author or a
manager of the board, or by a manager alone when the author is gone; the removal is recorded in the task's history (GWd-M2).

Accepted residuals (audit GW, all pre-existing unless noted):

- Low (since 1.27.0). In `text` and `both` modes `anonymise()` does not reach the *Fields* document. `eraseByValue()` and `eraseBoard()` do, and keep a suppression record of each line, so the same value does not return on a re-sync, also under a renamed field; a different value does, as for typed values. A value under 8 characters with no `@`, such as "yes" or a short name, is recorded with its field name, so it can return if that field is renamed. A value of 8 characters or more, or with `@`, stays out under every field of that document, including another field that holds the same value. The record of such a value ignores case since 1.29.0. A log that keeps no value repeats an erasure with `SyncFieldValueEraser::eraseField($task, $fieldName)`, which the host authorises; after a secret change, run a full sync first. A document holds at most 200 records. Past that the oldest line can return, as can every line if `kernel.secret` changes. `eraseBoard()` with types cannot tell a type from a line, so `text` mode is only erased as a whole. Clear the value at the source too. `users` values there hold names, no e-mails.
- Low (since 1.28.0). The *Fields* document is marked when the sync writes it. One written before 1.28.0 and not written since is recognised only strictly, by its title in the bundle's locales with the label `(ClickUp)` and nothing but `- **name**: value` lines; a person's added line keeps it from being recognised until the next write. Run one full sync before erasing. A person's document with such a title and only such lines is treated as the sync's. A title under another provider's label, or a host's own translation, is not recognised before the mark.
- Low. `anonymise: true` clears the user's reference and name on history rows, and since 1.29.0 the member rows that name them by id (values and `context`). Rows from before 1.29.0 have no id, so they go by the user's current label: a value under an earlier label stays, the match is global, and a user who takes another person's label and is erased clears that person's name from those older rows; the rows stay.
- Host note: attribution only is read by the sync. Keep flagged users out of your assignable lists; file imports and the member UI do not consult it.
- Host note: a `BOARD_ACCESS_CHECK` listener that grants every action makes every editor a board manager, including for removing another user's time entry.
- `TimeTrackBundle` entries (`TimeEntry.user`) are that bundle's own; this rule and this audit do not reach them.
- CSRF token ids are bound to the action, not the target (REQ-SEC-005 asks for that).

## Dependencies

Run `composer audit` and Dependabot before releases.

TaskBoard has no required dependency on TimeTrack. Install `nowo-tech/time-track-bundle` only when you need timer integration.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for coordinated disclosure.

## Release security checklist

- [ ] No secrets in repo or demo `.env` committed
- [ ] `composer audit` clean
- [ ] Manage routes documented in INSTALLATION
- [ ] Access checker and events documented for integrators
- [ ] Input/output validation + Twig escaping for task content
- [ ] CSRF on all session mutations (forms + bare POSTs / kanban `fetch`) — REQ-SEC-005
- [ ] No secrets in logs
- [ ] AI security audit grade recorded (see `BUNDLES_SECURITY_ANALYSIS.md` — REQ-SEC-004)
