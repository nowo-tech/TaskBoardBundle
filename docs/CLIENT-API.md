# Client API v1 (`/task-board/v1`)

Since 3.4.0. A stateless, versioned API for a **first-party app** — Beacon Desktop's Tasks module (phase BD-3b of
[`BEACON_DESKTOP_TASKS.md`](https://github.com/nowo-tech/beacon-desktop/blob/main/docs/design/BEACON_DESKTOP_TASKS.md)) —
built as a thin facade over the [Task API](API.md)'s guard, operations, audit and idempotency store. It adds the app's
shape (contexts, cursors, buckets, `tokens/1` text, per-reader references) and adds **no second copy of any rule**: reach,
plan, the person's rights, the column transition policy, revisions, history, notifications and the audit are the ones
the web UI and the Task API use.

`/api/tasks/v2` (the ClickUp-shaped Task API for scripts, agents and the MCP server) is unchanged.

The contract is [`openapi/task-board-client-v1.yaml`](openapi/task-board-client-v1.yaml) (OpenAPI 3.1), kept in step with
the routes by `ClientApiOpenApiTest`.

## Table of contents

- [Turning it on](#turning-it-on)
- [Ports the host implements](#ports-the-host-implements)
- [Conventions](#conventions)
- [Routes](#routes)
- [Text: tokens/1](#text-tokens1)
- [Permission filtering per reader (R2)](#permission-filtering-per-reader-r2)
- [Writes, revisions and idempotency](#writes-revisions-and-idempotency)
- [Errors](#errors)
- [Worker mode and concurrency](#worker-mode-and-concurrency)
- [Not in 3.4.0](#not-in-340)

## Turning it on

Off by default: without `client_api.principal_resolver` there is no route, service, listener or parameter. It needs
`api.enabled` (with its hooks: rate limiter, plan gate, workspace, users, members) and a context policy.

```yaml
nowo_task_board:
    api:
        enabled: true
        # … the Task API hooks (see API.md)
    client_api:
        principal_resolver: App\Tasks\DesktopTaskTokens      # TaskClientPrincipalResolverInterface
        context_policy: App\Tasks\DesktopTaskTokens          # TaskClientContextPolicyInterface
        reference_resolver: App\Tasks\DesktopTaskReferences  # optional, tokens/1 references
        link_visibility: App\Tasks\DesktopTaskReferences     # optional, host-object links
        file_resolver: App\Tasks\DesktopTaskFiles            # optional, tokens/1 images
        source_link: App\Tasks\DesktopTaskSources            # optional, POST tasks with source
        file_scheme: beacon-file                             # the scheme of host files in Markdown images
        prefix: /api/task-board                              # routes under /api/task-board/v1
        context_header: X-Beacon-Context
```

TaskBoard's access checkers read the security token, so the client API sits behind a **stateless firewall** whose
authenticator is the bundle's (`nowo_task_board.client_api.authenticator`), listed **before** a firewall that would
match the same prefix (for example the Task API's `^/api/`):

```yaml
security:
    firewalls:
        task_board_client:
            pattern: ^/api/task-board/v1/          # ^%nowo_task_board.client_api.v1_path_prefix%/
            stateless: true
            provider: app_users
            custom_authenticators: [nowo_task_board.client_api.authenticator]
            entry_point: nowo_task_board.client_api.authenticator
```

Without that firewall the API fails closed (`500 server_error`, logged).

## Ports the host implements

| Port | Required | What it answers |
|---|---|---|
| `ClientApi\Contract\TaskClientPrincipalResolverInterface::resolve($bearer, $request)` | yes | The `TaskClientPrincipal` of a valid token of the tasks family **now** (user = the host's security user, scopes, a non-secret token id, the device label, a bound context or null); null otherwise (unknown, expired, revoked, another family). Called once per request, never cached. |
| `ClientApi\Contract\TaskClientContextPolicyInterface` | yes | `contextsFor()` (the switcher), `allows($principal, $key)` (every request), `whereFor($principal, $key)` (the tenants of the context as `TaskApiWhere::limitedTo(...)`; never `everything()` — a context whose `where` is everything lists nothing, fail closed). |
| `ClientApi\Contract\TaskClientReferenceResolverInterface::reference($reader, $type, $id)` | no | A `#[title](type:id)` reference as the reader may see it (`TaskClientReference`: type, id, the host's title, a same-origin or `https://` URL), or null. Without it every reference is `{visible: false}`. |
| `Link\TaskObjectLinkVisibilityInterface::visibleTo($viewer, $type, $id)` | no | Whether a host-object link of a task is shown to this reader. Without it every host-object link is `{visible: false}` in the client API (the web UI is unchanged). |
| `ClientApi\Contract\TaskClientFileResolverInterface::canView($reader, $fileId)` | no | Whether an image `![alt](<file_scheme>:<id>)` is shown (`{visible: true, fileId, alt}`); otherwise `{visible: false}` with no id and no alt. |
| `ClientApi\Contract\TaskClientSourceLinkInterface::link($principal, $source)` | no | The host object a new task links to for its `source` (Beacon: a chat message the person may read now), or null (`422 source_not_visible`). Without it `source` is `400 invalid_field`. |

The principal becomes a `TaskApiPrincipal` per request: level **Read** with `task-board:read`, **Tasks** with
`task-board:write` too (never Full, never My tasks), `where` from the context, channel **`app`**
(`ColumnTransitionVia::APP`; not a machine channel, so *humans only* columns let it in, Q-BD-24), token name = the
device label. The person's budgets come from `api.hooks.rate_limiter` (`consume()`, shared with the personal tokens).

## Conventions

- **Stateless**: Bearer only (at most 4096 bytes); cookies, sessions and CSRF are never read; answers are
  `Cache-Control: no-store, private` and never set a cookie. No CORS.
- **Context**: `X-Beacon-Context: personal` or `org:<uuid>` on every request (unless the token is bound to one, then a
  header naming another context is refused). Unknown, revoked and foreign contexts are one answer: `403
  context_forbidden`. A `whereFor()` that answers `TaskApiWhere::everything()` is a host error: `500 server_error`,
  logged (audit ZCS-L1).
- **Throttling before authentication** (audit ZCS-I6): the person's budget is spent only once the token, the access and
  the context are accepted. Unknown tokens, scope and context refusals are not counted by the bundle: the host throttles
  them per IP (the denied event carries the `/24` or `/64`) or at its firewall, as Beacon's family authenticator does.
- **Ids** are the bundle's UUIDs; people are the host's public ids (`TaskApiUsersInterface::apiId()`); never an e-mail.
- **Dates** are RFC 3339; answers in UTC (`Z`). `allDay: true` when the task's dates are midnight UTC (the bundle's
  date-only dates); a write with `allDay: true` keeps the date part only.
- **Cursors** are opaque and **sealed** (audit ZCS-L2): the sort values of the last scanned row are encrypted and
  authenticated with keys derived from `kernel.secret` (HMAC-SHA-256 in counter mode, a random nonce, a 128-bit tag),
  bound to their list, sort and context; a changed cursor, one of another list or one made with another secret is `400
  invalid_cursor`. Rotating `kernel.secret` invalidates the cursors in flight (the app reloads the list). Since 3.4.1
  the client API refuses an empty `kernel.secret` (build or first request) and pads the sealed values to 32 bytes, so
  a cursor's length does not tell a value's digit count. `limit` 1–100.
  A page may hold fewer rows than `limit` when rows it scanned are not visible to the reader. `nextCursor: null` at the
  end.
- **Repeatable query parameters** (`priority`, `tag`) are read as `?tag=a&tag=b` or `?tag[]=a&tag[]=b`.

## Routes

Read routes need `task-board:read`, writes `task-board:write` (else `403 insufficient_scope`).

| Method and path | Notes |
|---|---|
| `GET me` | `{apiVersion: 1, user, scopes, context, contexts, features, limits, enums}`; `features.realtime`, `templates`, `files`, `activity` are false in 3.4.0 |
| `GET my-tasks?bucket=&today=&cursor=&limit=` | The caller's open tasks (open or custom column, not completed, not archived) as **assignee** in the context's boards; `bucket` = `overdue`, `today`, `upcoming` (the next 14 days), `no_date`, `review` (as **reviewer**), `all` (default). `today` = the client's local date, within a day of the server's UTC date (else `400 invalid_date`). Sorted by due date, priority, id. The first page also returns `counts {overdue, today, upcoming, review}`. At most 2000 open assignments are read per request. |
| `GET boards?cursor=&limit=&q=` | The context's boards the person may view, by name; `group` = the board's folder, else the host's space of its tenant (`{type: folder\|space, id, label}`). No counts in the list (audit ZCS-L2). |
| `GET boards/{id}` | The board with `counts {open, capped}` (open tasks the reader may open; at most 2000 are read, `capped` says when there were more), the people the caller may assign there (100 at most) and the tags in use (50 at most). |
| `GET boards/{id}/tasks?column=&sort=&cursor=&limit=&assignee=me&priority=&tag=&q=&due=&parent=` | With `column`: that column's top-level cards in board order (30 by default). Without: the list view, `sort` = `position` (default), `due`, `priority`, `updated`, `number`. `due` = `overdue`, `today`, `week`, `none` (UTC days); `q` = the Task API's search (`search.max_matches`, else `400 search_too_broad`); `parent=<id>` lists one task's subtasks. |
| `GET tasks/{id}` | The task's detail with `ETag: "<revision>"`. `GET tasks/123?board=<id>` (or `#123`) looks it up by number. |
| `GET tasks/{id}/subtasks?cursor=&limit=` | One level, board order. |
| `GET tasks/{id}/comments?before=&limit=` | Newest first (20 by default); bodies as tokens/1. |
| `GET tasks/{id}/questions?state=` | With `task_questions.enabled` (else `404`): the Task API's question shape, `context` as tokens/1, `waitingForMe`. |
| `GET questions/waiting` | Open questions addressed to the caller on tasks of the context (100 at most). |
| `GET people?q=&board=` | People the caller may assign on the board whose name starts with `q` (20 at most; the Task API's member lookup minus service accounts). |
| `GET changes?since=&limit=` | Polling: tasks of the context the reader may open changed since the cursor (`[{taskId, boardId, revision}]`, at most 200), `nextCursor`, `reset` (true without `since`, when the cursor is older than 24 hours, or when more than `limit` changed: reload what is shown). No tombstones. |
| `POST tasks` | Quick create (`Idempotency-Key` required). Body: `boardId`, `title`, optional `columnId` (else the first open column), `description`, `priority`, `dueAt`, `startAt`, `allDay`, `estimateMinutes`, `tags`, `assignees` (`"me"` or public ids), `parentId`, `source`. `201 {task}` + `ETag`. |
| `PATCH tasks/{id}` | Any of `title`, `description`, `priority`, `color`, `tags` (the whole list), `dueAt`, `startAt`, `allDay`, `estimateMinutes`, `archived`; `If-Match` required. |
| `POST tasks/{id}/move` | `{columnId, beforeTaskId?, afterTaskId?}`. A column change needs `If-Match: "<revision>"`; a reorder inside the same column accepts `If-Match: *` (last writer wins, no revision bump, as the web board's drag and drop). The column transition policy decides with operation `move` and channel `app`; a refusal is `403 transition_refused {policyCode, message}`. |
| `PUT tasks/{id}/members/{userId}` / `DELETE …` | `{role: assignee\|reviewer\|stakeholder\|watcher}` (`userId` may be `me`); the board's assignee rule applies; `If-Match` required. `DELETE` removes every role of that person. |
| `POST tasks/{id}/checklist-items` | `{checklist, title}` (a subtask tagged `checklist:<name>`); `Idempotency-Key` required; `201 {task (the parent), itemId}`. |
| `PATCH tasks/{id}/checklist-items/{itemId}` | `{title?, done?}` with the **item's** `If-Match`; `done` moves it to the done column or back to the first. `200 {task, itemId, itemRevision}`. |

Every answer that shows one task carries its revision as `ETag`.

## Text: tokens/1

Descriptions, comments and question contexts are `{format: "tokens/1", blocks: [...]}` — the format of ChatKit's client
API (Q-BD-16, Q-BD-25), produced by TaskBoard's own converter (`ClientApi\Text\TaskTextTokens`):

- blocks: `paragraph`, `heading` (`level` 1–3), `quote`, `list` (`ordered`, `items`; a task list adds `checked`), `code`,
  `rule`;
- inline tokens: `text` (`marks`: `bold`, `italic`, `strike`), `break`, `code`, `link` (`http(s)` and `mailto` only),
  `reference`, `image`.

A `reference` (`#[title](type:id)`) is the host's for this reader: `{visible: true, refType, id, title, url}` (the title
the host returns; the one written in the text is never shown), else `{visible: false, text: "Content not visible"}`.
References inside code are never resolved. An `image` is `{visible: true, fileId, alt}` only for a host file the reader
may open; any other image is `{visible: false}`. Mentions stay text (TaskBoard keeps a comment's mentioned users beside
the body). The app renders every string as a text node.

Limits (audit ZCS-M1, ZCS-I4): a text is read up to 64 KB, cut on a character boundary (the body then carries
`truncated: true`); emphasis (`bold`, `italic`, `strike`) is read on lines of at most 4 KB, over the first 16 KB of a
text and for at most 256 spans, each at most 2000 characters — beyond that the text is plain `text` tokens. The
conversion is linear: one pass over each line, only a span's inside read again.

## Permission filtering per reader (R2)

- Every list is narrowed by the context's `where`, then by the person's access (the guard's reach, `canViewBoard()`,
  `canViewTask()`, the `BOARD_ACCESS_CHECK` / `TASK_ACCESS_CHECK` listeners, the plan gate) **before** it is paged or
  counted. A single object out of reach, unknown or of another context is `404 not_found` (one answer); every 404 of an
  authenticated call dispatches `TaskClientApiDeniedEvent` (`not_found`).
- A parent, subtask or dependency the reader may not open is `{visible: false}` with no id, number or title.
- Host-object links: `{visible: false}` unless `TaskObjectLinkVisibilityInterface` says yes; attachment links are
  `{visible: false}` in 3.4.0.
- People: the host's profile for this reader (`TaskApiUsersInterface::profiles()`); an avatar URL is kept only when it
  is same-origin or `https://`.

## Writes, revisions and idempotency

- **`If-Match: "<revision>"`** is required on every change of an existing task (Q-BD-29): missing is `428
  precondition_required`; another revision is `409 conflict {currentRevision}` and nothing is written.
- **`Idempotency-Key`** is required on `POST tasks` and `POST tasks/{id}/checklist-items`, optional on the other writes:
  the Task API's store (`api.hooks.idempotency_store`), 24 hours, scoped to the token exactly as the Task API scopes it
  (`sha256('nowo-task-board-api|' . tokenId)`), so `TaskApiIdempotencyStoreInterface::forget()` on revocation or erasure
  drops both APIs' answers. Another request with the same key (another body, query, context or `If-Match`: a fresh revision needs a fresh key, audit ZCS-I3) is `409 idempotency_mismatch`; while it runs, `409
  idempotency_in_flight`. A replay answers `Idempotency-Replayed: true` and re-checks reach first.
- Every write dispatches `API_WRITE` with `channel: app` (without a listener: `503 audit_unavailable`); creates ask the
  host's plan limits (`403 plan_limit`).

## Errors

`{ok: false, error: <code>}` plus the fields noted.

| Status | Codes |
|---|---|
| 400 | `invalid_context`, `context_required`, `invalid_cursor`, `invalid_limit`, `invalid_json`, `invalid_field` (`field`), `invalid_date`, `search_too_broad`, `parent_refused` (`reason`) |
| 401 | `invalid_token` (+ `WWW-Authenticate: Bearer error="invalid_token"`) |
| 403 | `insufficient_scope` (+ `WWW-Authenticate`), `context_forbidden`, `forbidden`, `plan_limit` (`reason`: `tasks_module`, `limit`), `transition_refused` (`policyCode`, `message`) |
| 404 | `not_found` |
| 409 | `conflict` (`currentRevision`), `parent_refused` (`reason`), `idempotency_mismatch`, `idempotency_in_flight`, `unavailable` (`code: CONFLICT_001`, `Retry-After`), `partial` (`code: CONFLICT_002`: read again) |
| 413 | `invalid_json` (a body over `api.max_body_bytes`) |
| 422 | `source_not_visible` |
| 428 | `precondition_required` |
| 429 | `rate_limited` (+ `Retry-After`) |
| 500 | `server_error` |
| 503 | `audit_unavailable`, `idempotency_unavailable` |

Every refusal from authentication on dispatches `TaskBoardEvents::CLIENT_API_DENIED` (`TaskClientApiDeniedEvent`: the
reason, the token id and user id when known, the `/24` or `/64` network; ids and codes only).

## Worker mode and concurrency

- FrankenPHP worker mode with the kernel kept alive (REQ-RUNTIME-002): the principal lives in a request attribute, the
  context and `where` are request data, references and files are memoised per text conversion only; no static cache.
  `ClientApiWorkerTest`: one kernel, 500 rounds, two people in two contexts, a board grant flipped every round, a token
  revoked and a membership removed half-way, flat memory.
- REQ-RUNTIME-003: every change runs in one short transaction (`TaskApiTransaction`: rollback to the entry level, the
  connection closed when that rollback fails, the original exception rethrown, the events held until the commit); the
  column a move or a checklist tick enters is locked before the task's (or item's) row (the Task API's order, audit
  ZCS-L3), then `If-Match` is compared on the locked row and the change is built from the row as locked (audit
  ZCS-I1); the host's source link is asked before the transaction (no I/O under the locks). See [CONCURRENCY](CONCURRENCY.md).

## Not in 3.4.0

Realtime (`GET realtime`, the change publisher) is 3.5.0, as are comments, questions and subtasks writes, parent
changes, `files/{id}`, templates and the activity panel. Never in v1: deleting tasks (archive instead), board and column
management, dependencies and links editing, custom field values, uploads, time entries (the TimeTrack client API owns
time), bulk updates.
