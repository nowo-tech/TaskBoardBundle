# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [Access control model](#access-control-model)
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
| **Malicious import files** | Oversized or crafted CSV/JSON uploads. | Authenticated route only; Symfony file upload limits; parsers validate structure; import runs in orchestrator with explicit source selection. |

## Access control model

1. **Route level** — `TaskBoardAccessCheckerInterface` (default: role-based via `ConfigurableTaskBoardAccessChecker`).
2. **Resource level** — `TaskBoardResourceAccessChecker` (board creator, team members, task members, `security.manage_roles`; toggle with `security.resource_scoping`), overridable by `BOARD_LIST_QUERY`, `BOARD_ACCESS_CHECK` and `TASK_ACCESS_CHECK` listeners.
3. **Time tracking** — when TimeTrack is installed, `TaskAccessGuard` validates assignee or team membership before exposing tasks to TimeTrack.

## Logging and personal data

REQ-OBS-001 (since 1.21): every successful board / task write logs one `info` line, an invalid CSRF token one `warning`,
and every import its start, result and failure ([CONFIGURATION — Logging](CONFIGURATION.md#logging-since-121)). The
context holds only the action, the ids of the board objects (UUIDs) and counts. The bundle never logs user ids, names,
e-mails, task titles or descriptions, comment bodies, link URLs, uploaded file names or contents, nor tokens. There is
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
