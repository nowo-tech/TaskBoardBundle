# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [Access control model](#access-control-model)
- [Provider sync: host duties and known limits](#provider-sync-host-duties-and-known-limits)
- [Logging and personal data](#logging-and-personal-data)
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
| **Hard delete of a mapped user (since 1.25)** | Deleting a user cascades the imported tasks and comments projected onto them. | `detachUser()` before the user delete (documented order in UPGRADING); `SET NULL` + snapshot planned for 2.0. |
| **Token scope for time (since 1.25)** | Importing time needs an admin token, which is more powerful. | Every provider call is a `GET` to the constant base URI (`ProviderHttpClient` has no other verb); `assignee` ids come only from the directory; the run stores only `time_mode`. ClickUp tokens have no read-only scope: read-only is a bundle guarantee. |
| **Resource abuse (since 1.25)** | Huge workspaces, endless window splits, mass reassign. | `max_windows_per_run`, window halving down to one hour, assignee chunks, the E5 cap, scans ≤ 15 calls, list search ≤ 40 calls, reassign batches with a cursor. |
| **Remote names and descriptions (since 1.25)** | Controls, bidi or markup in names. | Names are one sanitised line (≤ 120); ids are pattern-checked; time-entry descriptions are plain text (controls and bidi stripped) and passed to the host only, never stored or logged by the bundle. |
| **Malicious import files** | Oversized or crafted CSV/JSON uploads. | Authenticated route only; Symfony file upload limits; parsers validate structure; import runs in orchestrator with explicit source selection. |

## Access control model

1. **Route level** — `TaskBoardAccessCheckerInterface` (default: role-based via `ConfigurableTaskBoardAccessChecker`).
2. **Resource level** — `TaskBoardResourceAccessChecker` (board creator, team members, task members, `security.manage_roles`; toggle with `security.resource_scoping`), overridable by `BOARD_LIST_QUERY`, `BOARD_ACCESS_CHECK` and `TASK_ACCESS_CHECK` listeners.
3. **Time tracking** — when TimeTrack is installed, `TaskAccessGuard` validates assignee or team membership before exposing tasks to TimeTrack.

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
- **The erase cursor (FP-L3).** It is signed (HMAC, `kernel.secret`) and bound to the board, the actor and the
  operation; still keep it server-side (a Messenger message), never in a browser round trip.
- **Progress polling (EG-I7).** `GET …/connection/progress/{runId}` advances an inline run by one slice (contract §6.1).
  It needs the unguessable run id and the manage right; it is not a practical CSRF target, but it is a GET with an
  effect.

## Logging and personal data

REQ-OBS-001 (since 1.21): every successful board / task write logs one `info` line, an invalid CSRF token one `warning`,
and every import its start, result and failure ([CONFIGURATION — Logging](CONFIGURATION.md#logging-since-121)). The
context holds only the action, the ids of the board objects (UUIDs) and counts. The bundle never logs user ids, names,
e-mails, task titles or descriptions, comment bodies, link URLs, uploaded file names or contents, nor tokens.
Since 1.25 the never-logged list also covers the sync's people: person names and e-mails, provider user ids together
with names, and time-entry descriptions (logs and events carry directory, person and board ids and counts only). Names
and e-mails of remote people live only in `task_board_sync_people` (cleared by `anonymise()`); no avatar URL is stored. There is
no `dump()`, `var_dump()` or `error_log()` in `src/`.

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
