# Task API

A REST API shaped like ClickUp API v2, for scripts and agents with a personal token (spec 213, since 2.2). Off unless `api.enabled: true`. **Audit IA (2026-11-08): Pass-Low at `637494e`.**

## Table of contents

- [Turning it on](#turning-it-on)
- [What a host provides](#what-a-host-provides)
- [Firewall](#firewall)
- [Access](#access)
- [Read endpoints](#read-endpoints)
- [Write endpoints](#write-endpoints)
- [Management endpoints (Full)](#management-endpoints-full)
- [Task templates (since 2.16)](#task-templates-since-216)
- [Idempotency](#idempotency)
- [Errors](#errors)
- [Differences from ClickUp](#differences-from-clickup)
- [Known differences from the UI](#known-differences-from-the-ui)
- [Material for a host's manual](#material-for-a-hosts-manual)

With `api.enabled: false` a host gets no route, service, listener, parameter or table. Paths below are relative to `api.prefix` (default `/api/tasks`).

## Turning it on

1. Implement the hooks below and set them under `api.hooks`. Only `authenticator` is required to build; every other default denies, so a host sets them all.
2. Put the prefix behind a stateless firewall that authenticates the token (below).
3. Listen to `TaskBoardEvents::API_WRITE` (audit) and to `TaskBoardEvents::QUOTA_CHECK` (plan limits for task and board creates).
4. Create the `task_board_api_idempotency` table (`doctrine:migrations:diff`), unless you set `api.hooks.idempotency_store`; schedule `nowo:task-board:api:purge-idempotency`; call `forget()` when a token is revoked or its person erased.
5. Make the listeners of `TASK_ERASED` and `BOARD_DELETED` idempotent: a board deleted twice at once can fire them twice.

The full contract is `docs/openapi.yaml` (OpenAPI 3.1), kept in step with the routes by `ApiOpenApiTest`.

## What a host provides

The bundle stores no tokens. `api.hooks.authenticator` is required with `enabled: true`; every other hook has a default, and every default denies.

| `api.hooks.*` | Interface in `Nowo\TaskBoardBundle\Api\Hook` | Without it |
|---|---|---|
| `authenticator` | `TaskApiAuthenticatorInterface` | The container does not build |
| `rate_limiter` | `TaskApiRateLimiterInterface` | Every request 429 |
| `plan_gate` | `TaskApiPlanGateInterface` | 403 `PLAN_001` |
| `workspace` | `TaskApiWorkspaceInterface` | No teams or spaces, every board 404 |
| `users` | `TaskApiUsersInterface` | Nobody is known |
| `time_entries` | `TaskApiTimeEntriesInterface` (list and log; tracked time is never deleted through the API) | 403 `TIME_001` |
| `attachments` | `TaskApiAttachmentsInterface` | 403 `FILE_001` |
| `idempotency_store` | `TaskApiIdempotencyStoreInterface` | The bundle's store, when writes arrive |

A listener on `TaskBoardEvents::API_WRITE` (`TaskApiWriteEvent`) audits each write. Since 2.4.0 the event's `channel` (from `TaskApiPrincipal::$channel`) says where the call came from: `api` (default) or `mcp`, when the host's authenticator builds the principal for its MCP server with `channel: TaskApiPrincipal::CHANNEL_MCP`, derived from the call path and never from anything the client sends. The channel is a lowercase label (`[a-z][a-z0-9_]{0,31}`, else `InvalidArgumentException`) and changes no access decision.

## Firewall

The API must sit behind a **stateless firewall** whose security user is the token's user, as the access checkers read the security token. `security: false` answers 500 `SERVER_001` on every request. For a bad token the firewall lets the request through with no user, and adds no redirecting `access_control` on the path: the bundle answers, so all refusals share one shape.

```yaml
nowo_task_board:
    api:
        enabled: true
        prefix: /api/tasks
        host_decides_access: true   # only with security.resource_scoping: false, when your listeners decide access
        hooks:
            authenticator: App\Api\TaskApiAuthenticator
```

The build refuses `security.allow_unauthenticated: true`, and `security.resource_scoping: false` without `host_decides_access: true`: both would open every board to every token.

## Access

Each request: level ∩ the person's access ∩ where. In this order, and the first failure answers:

| Check | Answer |
|---|---|
| Out of reach: missing, outside where, no space for its tenant, no view access, for My tasks not its own | 404 `ACCESS_001`, always the same |
| The tasks module is not in the plan | 403 `PLAN_001` |
| The level may not | 403 `TOKEN_001` |
| The person may not | 403 `ACCESS_002` |

Levels are not a chain: *Read* changes nothing, while *My tasks* works on its own tasks. See `TaskApiCapability`.

**Where only narrows.** `TaskApiWhere::everything()` grants the person's whole reach: map a token's explicit "everything I can reach" to it, and **never an empty, missing or unreadable scope**, which must be `TaskApiWhere::limitedTo([], [])` (nothing). Access itself always comes from the checkers.

**Where.** `TaskApiWhere::limitedTo($tenantKeys, $boardIds)`: each entry grants on its own. A named tenant reaches all its boards; a named board is reached even when its tenant is not listed. Nothing else widens: the person's access, the workspace hook and the tenant check apply to it too.

**Tenancy.** With `tenancy.enabled`, a board is reached only when the bundle's tenant resolver opens its tenant for the person (the slug being the key), as the UI asks of the tenant in its URL, whatever the workspace hook answers. A board without a tenant is not served then, as in the UI.

**Notes for hosts.** The security-user check compares ids, not classes: a firewall user of another class with the same id would pass, so use one user class for the API's firewall. An `access_control` refusal on the API's path answers 403 `ACCESS_002`, not 401: add none there. A token whose user the access checker refuses answers 401 like a bad token and counts as a failed token.

### Logging time

Since 2.4.0, `POST /v2/team/{team_id}/time_entries` with a `tid` checks `TaskBoardResourceAccessChecker::canTrackTime()` where other task writes check `canEditTask()`. It dispatches `TASK_ACCESS_CHECK` with `TaskAccessCheckEvent::ACTION_TRACK_TIME` (`track_time`): `grant()` allows a person who may view the task to log time on it without editing it, `deny()` refuses an editor too (403 `ACCESS_002`) and sticks against a later listener's `grant()`, and with no answer (or a grant marked read-only) the edit right decides, as before 2.4.0. Reach and level are checked first, as for every task: *My tasks* logs time on its own tasks only, *Read* is refused (`TOKEN_001`). Listing time needs only the read level and is unchanged; deleting time is always refused; there is no update route.

A `track_time` grant ignores a read-only board (as any explicit task grant does), so a listener that freezes boards should check that itself. A read-only mark from any listener on the `track_time` event cancels the grant and falls back to the edit right: listeners that mark tasks read-only should return early on `ACTION_TRACK_TIME` when the grant should apply. The task page's *Log time* form still needs the edit right.

## Read endpoints

Paths after `api.prefix`. *Level* is the least a token needs; the person's access and where still apply.

| Endpoint | Level | Notes |
|---|---|---|
| `GET /v2/user`, `GET /v2/team` | My tasks | A team lists no members |
| `GET /v2/team/{team_id}/space`, `GET /v2/space/{id}` | My tasks | Spaces where reaches and whose plan holds the module |
| `GET /v2/space/{id}/folder` | My tasks | Always `{"folders": []}`: every board is a folderless list |
| `GET /v2/space/{id}/list`, `GET /v2/list/{id}` | My tasks | `task_count` counts only what the token may read; columns are `statuses` (first `open`, last `closed`, the rest `custom`) |
| `GET /v2/list/{id}/task`, `GET /v2/team/{team_id}/task` | My tasks (its own) | 100 per page from `page=0`, `last_page`; `archived`, `subtasks`, `include_closed`, `statuses[]`, `assignees[]`, `tags[]`, `due_date_gt`/`_lt`, `date_created_*`, `date_updated_*`, `date_done_*`, `order_by` (`id`, `created`, `updated`, `due_date`), `reverse`, `include_markdown_description`; on a team also `space_ids[]`, `list_ids[]` |
| `GET /v2/task/{id}` | My tasks (its own) | `include_subtasks`, `include_markdown_description`. Since 2.10.0, `custom_task_ids=true` with `space_id` or `list_id`: `{id}` is the task's number (see [Task numbers](#task-numbers)) |
| `GET /v2/task/{id}/comment` | My tasks (its own) | 25 per page, newest first; `start` and `start_id` of the oldest one shown give the next page |
| `GET /v2/task/{id}/member` | Read | Everyone attached to the task |
| `GET /v2/space/{id}/tag` | Read | Tags of the open tasks the token reaches |
| `GET /v2/list/{id}/field` | Read | Custom fields a provider sync imported; none otherwise |
| `GET /v2/team/{team_id}/time_entries` | My tasks | The host's time store answers; `start_date`, `end_date`, `assignee`, `task_id` |

A malformed filter value is 400 `INPUT_002`. Unknown query parameters are ignored on reads. Each list answers in the same number of queries whatever the page holds (8 for a list's tasks, measured at 25 and 100 tasks), the tag filter included.

**Cost per board.** A team's lists and tasks ask the host's access decision once per board the team reaches (`BOARD_ACCESS_CHECK`, in Beacon a voter), and the plan gate once per space. The bundle's own queries stay the same with 1 board or 10; the host's checks grow with the boards.

**Time entries.** Without `task_id`, the bundle checks only the team: the host's time hook must keep to what the token's level and where reach, and to the people whose time the person may see. With `task_id`, the task goes through the guard first.

**What a page shows of other tasks.** Subtasks, dependencies and links are shown only when the token may open the other task too. For *My tasks*, the checklist items of its own tasks are shown whoever created them, as it manages those checklists.

### Task numbers

Since 2.10.0 (spec 214), with `task_numbers.enabled`, every task body carries `number` (the `#123` the UI shows; null without the option or before the backfill reached the task). `custom_id` stays null.

To look a task up by its number, the API follows ClickUp's convention for custom task ids, `custom_task_ids=true` (ClickUp's `team_id` is accepted and ignored):

```text
GET /v2/task/123?custom_task_ids=true&space_id=SPACE_ID   numbers per tenant (task_numbers.scope: tenant)
GET /v2/task/%23123?custom_task_ids=true&list_id=LIST_ID  any scope; "#123" works URL-encoded
```

Why a space or a list: ClickUp's custom ids (`DEV-123`) are unique in a workspace because they carry the space's prefix; here a number is unique only in its sequence, which is a tenant (one space) or, with `scope: board` or a board without tenant, one list. `space_id` of a space whose numbers are per list is 400 `INPUT_002`, as is neither parameter. A number not in that sequence, a number a moved task left behind (it now has a number in its new sequence, and only that one answers, even after it moved back), or any number without the option is 404, the same as a task the token may not see; access, level and plan are then checked as for `GET /v2/task/{id}`. The provider's custom id of a synced task (`DEV-123`) is not looked up here.

## Write endpoints

Every write goes through the services the UI uses: its plan limits, events (notifications, mentions, activity), history and validation. Each answers like ClickUp; a field nothing read is listed in `warnings` (`unknown`, or `not_supported` for a ClickUp field with no equivalent here) and is never saved.

| Endpoint | Level | Notes |
|---|---|---|
| `POST /v2/list/{id}/task` | Tasks | `name`, `description` / `markdown_content`, `status` (a column's name), `priority` (1 urgent to 4 low), `due_date`, `start_date`, `time_estimate`, `tags`, `assignees`, `parent` (same board). Asks the host's plan limit |
| `PUT /v2/task/{id}` | My tasks (own: `status`, dates, `description` only) | `name`, `priority`, `time_estimate`, `assignees` and `watchers` (`add`, `rem`), `archived` need Tasks. A status change moves the task as the board does: the done column completes it, leaving it reopens it within the host's limit |
| `DELETE /v2/task/{id}` | Tasks | Erases the task and its subtasks; tracked time is kept, detached. The board's manage decision is needed. A task that follows a provider: 409 `TASK_002` |
| `POST /v2/task/{id}/comment` | My tasks (own) | `comment_text`. Mentions work as in the UI's comment box: each candidate written `@Label` in the text is mentioned, so `TASK_COMMENT`, `TASK_MENTION` and the host's notifications follow |
| `POST`, `DELETE /v2/task/{id}/tag/{name}` | Tasks | The task form's rules: a name of 1 to 64 characters, at most 20 tags. `PUT /v2/task/{id}` does not take `tags` (a warning) |
| `POST /v2/task/{id}/field/{field_id}` | Tasks | Always 409 `FIELD_001`: custom fields are a provider's, read-only |
| `POST /v2/task/{id}/dependency`, `DELETE` with `?depends_on=` or `?dependency_of=` | Tasks | Within a board (`LINK_002`); no cycle or duplicate (`LINK_001`) |
| `POST`, `DELETE /v2/task/{id}/link/{links_to}` | Tasks | A `Related` relation |
| `POST /v2/task/{id}/checklist`, `PUT`, `DELETE /v2/checklist/{id}` | My tasks (own) | A checklist with no item is not stored. Deleting archives its items |
| `POST /v2/checklist/{id}/checklist_item`, `PUT`, `DELETE …/checklist_item/{item}` | My tasks (own) | An item is a subtask tagged `checklist:<name>`, counted against the plan's active tasks. `resolved` moves it to the done column or back to the first. *My tasks* assigns an item only to its own person. Deleting archives it. Items are edited under the parent task's right, whoever created them; any editor who tags a subtask `checklist:<name>` makes it an item, visible to a *My tasks* token of the parent |
| `POST /v2/task/{id}/attachment` | Tasks | Multipart, field `attachment`. The host stores it through `TaskApiAttachmentsInterface`, with all its upload checks (content sniffing, plan storage, sizes); its refusal is the answer. The file's bytes are part of an idempotency key's request |
| `POST /v2/team/{team_id}/time_entries` | My tasks (own task) | `start` and `duration` (1 minute to 7 days) in milliseconds, `tid`, `description`. The token's own person's time only: another `assignee` is 403 `TOKEN_001`. With `tid`, the person needs the time-tracking decision, not the edit right (since 2.4.0; see [Logging time](#logging-time)). Without `tid`, the host's time store decides; `ManualTaskApiTimeEntries` needs a task. Since 2.12.0 `billable` (boolean; absent or null = the host default) |
| `PUT /v2/team/{team_id}/time_entries/{id}` | My tasks (own task) | Since 2.12.0: `billable` (required boolean) of one of the token's own entries; other fields are warnings. Own entries only (another person's is 404); the board-manager path is the task page. The edit right on the task applies (403 `ACCESS_002`); 409 `TIME_004` when its period is locked or submitted (TimeTrack timesheets); 403 `TIME_003` when the time store does not implement `TaskApiTimeEntryBillableInterface` |
| `DELETE /v2/team/{team_id}/time_entries/{id}` | — | Always 403 `TIME_002`, before anything is looked up: tracked time is never deleted through the API |

## Column transitions, revisions and bulk updates (since 2.16)

Beacon spec 234 (AI Flow). Every write that sets a status asks the host's `column_transition_policy` first, with the
principal's channel (`api`, or `mcp` for an in-process caller) and the operation (`create`, `move`, `bulk`,
`checklist`). A refusal is 403 with the policy's code and message, e.g. `{"err": "Only people can move tasks into this
status", "ECODE": "STATUS_HUMANS_ONLY"}` or a host's `WIP_LIMIT`; nothing is written. The bundled `HumansOnlyColumnTransitionPolicy` is
strict: ticking a checklist item into a humans-only column is refused too, unless `humans_only_policy.allow_checklist`.

| Endpoint | Level | Notes |
|---|---|---|
| `GET /v2/task/{id}` | My tasks | Since 2.16.0 the task carries `revision` (an integer, +1 on every change but a reorder) and the answer `ETag: "<revision>"` |
| `PUT /v2/task/{id}` | as before | `If-Match: "<revision>"` (also `n`, `W/"n"`; `*` = any) or `base_revision` in the body: the row is locked and compared; another revision is 409 `{"err", "ECODE": "TASK_409", "current_revision": n}` and nothing is written. The answer carries the new `ETag` |
| `POST /v2/task/bulk` | My tasks (own fields) / Tasks | `{"tasks": [{"id", "base_revision"?, "description" \| "markdown_content", "status", "tags" (the whole list), "priority", "due_date", "start_date", "assignees": {"add", "rem"}}]}`: 1 to 50 tasks of one space, all or none, one transaction. 200 `{"tasks": [<task>, …]}` in the request's order. Any refusal: `{"err", "ECODE": "BULK_001", "failures": [{"index", "id", "status", "ECODE", "err", "current_revision"?}]}` always with HTTP 409 (each item's own status and ECODE are in `failures`), nothing written. Any other field of an item is refused (`INPUT_002`). After the commit: one `API_WRITE` per task and one `API_BULK_WRITE` with the count |
| `PUT /v2/comment/{id}` | My tasks (own task) | `comment_text`; the comment's task must be one the token may comment on, then `api.hooks.comment_edit_policy` decides (default: its author). Answers `{}`. Missing, deleted, out of reach or not editable: 404 `ACCESS_001` alike |
| `GET /v2/team/{id}/member` | any | The team's members (`api.hooks.members`): `{"members": [{"id", "username", "email", "color", "initials", "profilePicture", "service_account"}]}`; `email` only on the viewer's own row. With `?name=`: a prefix of the name or of one of its words, case and accents aside, at most 20, `{"id", "username", "service_account"}` only. A lookup never assigns anyone by name. A team the viewer is not in: 404 |
| `GET /v2/team/{id}/task?query=…`, `GET /v2/list/{id}/task?query=…` | as the list | Since 2.16.0: every word (white space separated, at most 8, `query` ≤ 200 characters) in the title or description (`search.comments`: or a comment). Only within the boards the token reaches, before any page; the other filters and *My tasks* apply. More than `search.max_matches`: 400 `SEARCH_001` |

`GET /v2/team` lists each team's `members` the same way (`[{"user": {…, "service_account": bool}}]`; `[]` without
`api.hooks.members`). Status objects carry `humans_only`; `POST` / `PUT /v2/list/{id}/status…` accept `humans_only`
(boolean).

## Management endpoints (Full)

Every management write needs a *Full* token and the board's manage decision (`BoardAccessCheckEvent::ACTION_MANAGE`; in Beacon `project.tasks.manage`, `organization.tasks.manage`, or a personal board's owner); since 2.5.0 the status (column) paths also need the column decision (`BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS`, granted by default); a new board needs the host's create check for the space's tenant and the board plan limit.

| Endpoint | Notes |
|---|---|
| `POST /v2/space/{id}/list` | `name`, `content` / `markdown_content`. The token must reach the whole tenant (naming one board of it is not enough). The board gets the default columns, as from the UI |
| `PUT /v2/list/{id}` | `name`, `content`; the slug stays. `status`, `priority`, `assignee`, `due_date` are warned about |
| `DELETE /v2/list/{id}` | Erases every task, tracked time kept and detached, then the columns and the board. Works within a time budget: 200 `{}` when done, otherwise 202 `{"status": "in_progress"}` with `Retry-After`; call again until 200. A 202 is not kept for an idempotency key. A board that follows a provider: 409 `LIST_002`. Two deletes of one board at the same time may both pick the same tasks, so `TASK_ERASED` (with its file refs) and `BOARD_DELETED` can come twice: a host's listeners must delete files idempotently |
| `POST /v2/list/{id}/status` | **Not in ClickUp's API.** A column at the end: `status` (its name), `color` (hex), since 2.9.0 `icon` (one emoji of at most 16 bytes, or one of `board.column_icons` / `board.default_columns` icon ids such as `tabler:bug`; anything else is 400 `INPUT_002`) |
| `PUT /v2/list/{id}/status/{status_id}` | **Not in ClickUp's API.** Renames, recolours or (since 2.9.0) sets the `icon` of a column (`null` or `""` removes it); what is not given stays |
| `PUT /v2/list/{id}/status/order` | **Not in ClickUp's API.** `order` lists every column id once |
| `DELETE /v2/list/{id}/status/{status_id}` | **Not in ClickUp's API.** Its tasks move to the first remaining column (completed or reopened as the board's last column says, within the host's limit); the last column stays (409 `LIST_001`) |

Tags exist on tasks only: there is no space tag to create, rename or delete. Board members are not managed through the API.

**Time store.** `api.hooks.time_entries` lists and logs time; it has no delete. A host without its own may use `Nowo\TaskBoardBundle\Api\Hook\ManualTaskApiTimeEntries`: the bundle's manual entries, logged through `TaskTimeLogger` as the task page logs them, and listed for the token's own person on tasks the token may open (at most 500).

**Before any write.** A host must listen to `TaskBoardEvents::API_WRITE` (else 503 `AUDIT_001`), and, with `api.quota_listener_required` (the default), to `QUOTA_CHECK` for creates (else 403 `PLAN_002`). A refused limit is 403 `PLAN_002` with the host's reason.

**Several changes in one request are not one transaction.** They are saved one service at a time, as the UI's separate forms are, and each service's events go out after its save. Everything that can be refused is asked first (people, status, dates, limits), so a refusal changes nothing; a database failure between two saves can leave the first one saved. A client retrying with the same `Idempotency-Key` gets a fresh run after such a failure, since errors are not kept. **Known narrow race:** the limit is asked once before the first save, and the restore and the move ask it again as they save; if another write fills the limit in between, the restore is saved and the move then answers 500. Retrying the same request completes it.

## Task templates (since 2.16)

With `task_templates.enabled` (Beacon spec 234, FR-061):

- `GET /v2/list/{list_id}/taskTemplate` — the templates the board offers the token's person: the board's own, then its tenant's, each by name, each allowed by `TaskTemplatePermissionInterface::canUse()`. The board is reached as for creating a task in it (level Full, else 403; a board the token does not reach is 404). Answer: `{"templates": [{"id", "name", "scope": "board"|"tenant", "description" (the Markdown body), "tags": [{"name"}], "status" (the default column as a task's status, or null: for a tenant template, the board's column with exactly the template's `default_column_name`; no such column means the board's first column)}]}`.
- `POST /v2/list/{list_id}/task` accepts `template_id`. The template fills only what the body leaves out: `markdown_content` / `description`, `tags` and `status` set in the body win (an explicit `""` or `[]` too). Any id the board does not offer — unknown, of another board or tenant, a look-alike, or not allowed — is the same `400 INPUT_002 "Invalid template_id"`, before anything is written. The audit's `API_WRITE` names it in `targetIds['template']`. The default column takes the same create path as a `status`, so the [column transition policy](#column-transitions-revisions-and-bulk-updates-since-216) decides on it too: a template pointing at a *humans only* column is refused for an `api` / `mcp` caller with that policy's code and message, byte for byte the answer the same `status` would give.

Without the option the route does not exist and `template_id` is an unknown field (warned).

## Idempotency

Any write may carry `Idempotency-Key` (1 to 255 characters, else 400 `IDEMP_003`):

- **Scope:** the token. The same key from another token is another key.
- **Expiry:** 24 hours from the first attempt.
- **Retry of the same request** (method, path and body; JSON compared with its keys sorted): the first answer, its status and body, with `Idempotency-Replayed: true`. The write does not run again.
- **Same key, different request:** 409 `IDEMP_002`. While the first attempt runs: 409 `IDEMP_001`; a claim left for 5 minutes by a process that died is taken over.
- **Refusals and errors are not kept:** a retry is decided afresh.
- **A replay is checked again:** the pipeline, and whether the token still reaches what the write touched (a board that is gone leaves its tenant), else 404.
- **What a replay does not do:** it does not ask the level or the plan again (the first request did, and a replay writes nothing); it does ask the pipeline (token, rate limit) and the reach.
- **Storage:** the bundle's table `task_board_api_idempotency` (hashes of the token id and key, never either; the answer, which can hold task text). Expired rows go on each claim and with `nowo:task-board:api:purge-idempotency`. `forget()` clears one token's answers: text from a task may sit in other tokens' answers for up to 24 hours. On revoking a token or erasing its person, call `TaskApiIdempotencyStoreInterface::forget()` with the scope `sha256('nowo-task-board-api|' . $tokenId)`. A host may replace the store with `api.hooks.idempotency_store`; the table is then not mapped. A store that fails refuses keyed writes with 503 `IDEMP_004`.

## Errors

Every refusal is `{"err": "…", "ECODE": "…"}` (since 2.16.0 `TASK_409` adds `current_revision`, `BULK_001` adds `failures`, and `TASK_003` adds `task_id` and `scope`); `err` names no id or person. Answers carry `X-RateLimit-Limit`, `-Remaining`, `-Reset` (Unix seconds) and `Cache-Control: no-store, private`. The credential is read only from the `Authorization` header, with or without `Bearer`, and never logged.

| ECODE | HTTP | When |
|---|---|---|
| `OAUTH_017` | 401 | No `Authorization` header |
| `OAUTH_019` | 401 | Token not valid: unknown, revoked, expired, disabled or erased account, or a user the access checker refuses. One answer for every reason |
| `API_001` | 404 | The host's instance switch is off |
| `ACCESS_001` | 404 | Out of reach, or an unknown path under the prefix |
| `ACCESS_002` | 403 | The person may not do this write |
| `TOKEN_001` | 403 | The token's level may not |
| `PLAN_001` | 403 | The tasks module is not in the plan |
| `PLAN_002` | 403 | A plan limit (the host's reason in `err`), or no `QUOTA_CHECK` listener |
| `RATE_001`, `RATE_002` | 429 | The token's limit; failed tokens from this IP |
| `INPUT_001` | 400 | Malformed JSON, not an object, or too large (`api.max_body_bytes`) |
| `INPUT_002` | 400 | A field or filter that is not valid; `err` names it |
| `LIST_001` | 409 | The last column of a board |
| `LIST_002` | 409 | Deleting a board that follows a provider |
| `TASK_002` | 409 | Deleting a task that follows a provider |
| `TASK_003` | 409 | Since 2.15.1: the task cannot be numbered — its scope's counter belongs to a look-alike scope (`scope_key` still folding, see [UPGRADING](UPGRADING.md#to-2151)). Since 2.16.0 the answer adds `task_id` (the task that could not be numbered; for a create, the id it would have had) and `scope` (the scope **the caller's own board asks for**, never the look-alike key stored for someone else). It stays a **request-level** refusal, never an entry of a bulk update's `failures`: the write is all or none, so nothing may read as "the other items landed" |
| `LINK_001`, `LINK_002` | 400 | A dependency on itself, duplicated or closing a cycle; across boards |
| `FIELD_001` | 409 | Setting a custom field |
| `FILE_001` | 403 | No attachment store; the host's store may answer its own `FILE_*` |
| `TIME_001` | 403 | No time store; the host's store may answer its own `TIME_*` |
| `TIME_002` | 403 | Deleting a time entry |
| `TIME_003` | 403 | Since 2.12.0: the time store cannot change an entry's `billable` flag |
| `TIME_004` | 409 | Since 2.12.0: the time entry's period is locked or waiting for approval |
| `IDEMP_001` … `IDEMP_004` | 409, 409, 400, 503 | Idempotency: in flight, another request, bad key, store down |
| `AUDIT_001` | 503 | No `API_WRITE` listener |
| `STATUS_HUMANS_ONLY`, `STATUS_REFUSED`, a policy's own code | 403 | Since 2.16.0: the column transition policy refused the status (its message in `err`) |
| `TASK_409` | 409 | Since 2.16.0: `If-Match` / `base_revision` is not the task's revision (`current_revision`) |
| `BULK_001`, `BULK_002`, `BULK_003` | 409, 400, 400 | Since 2.16.0: a bulk item refused (`failures`), tasks of several spaces, more than 50 tasks |
| `SEARCH_001` | 400 | Since 2.16.0: the text search matched more than `search.max_matches` tasks |
| `SERVER_001` | 500 | Anything else, including a security user that is not the token's user. No detail |

## Differences from ClickUp

### What the contract test pins

`tests/Functional/Api/ApiClickUpContractTest` sends this API the request each ClickUp reference page describes and compares the answer's shape (field names and JSON types, never values) with the shape of the response example published on that page. **The ClickUp side comes from published examples, not live recordings**: `tests/Fixtures/clickup/shapes.json` was derived on 2026-11-08 from developer.clickup.com, without calling ClickUp. Where ClickUp's own examples disagree with each other, that is said below. A field present on both sides but empty (`null`) is not compared. Any difference not in this table fails the test, and so does a row that no longer applies. `*` stands for any text.

| Difference (`page: path: kind`) | Why |
|---|---|
| `*: *id: string, not number` | Ids are strings: the bundle's UUIDs, the host's ids for people. |
| `*: *.creator.initials: extra` | A creator has initials, as other people do. |
| `*: *.creator.email: extra` | A creator has an email field (null unless the host shows it). |
| `*: *.profileInfo: missing` | No profile details beyond name, email, colour and picture. |
| `getauthorizeduser: .user.*: missing` | No timezone, week start or font settings. |
| `*: *custom_item_id: missing` | No task types. |
| `*: *.locations: missing` | A task is on one board. |
| `*: *permission_level: missing` | Permissions are the host's; not reported per object. |
| `*: *.sharing: missing` | No public sharing. |
| `*: *.attachments: missing` | Files are the host's: not listed on the task. |
| `*: *.markdown_description: missing` | Given only with include_markdown_description. |
| `*: *.subtasks: missing` | Subtasks are given with include_subtasks=true only. |
| `*: *.tags[].creator: missing` | Tags have no creator. |
| `*: *.dependencies[].*: missing` | Dependencies have no chain, user or workspace id. |
| `*: *.linked_tasks[].userid: missing` | Links have no user id. |
| `*: *.custom_fields[].*: missing` | A task's custom field values carry id, name, type and value only. |
| `getaccessiblecustomfields: .fields[].*: missing` | Imported fields carry the options the provider gave; no per-type config. |
| `getaccessiblecustomfields: .fields[].required: extra` | Imported fields say they are not required. |
| `*: *inbound_address: missing` | No email-in address for boards. |
| `*: .due_date_time: missing` | Boards have no dates. |
| `*: .start_date_time: missing` | Boards have no dates. |
| `*: *.date_created: missing` | Checklists and their items carry no creation date of their own. |
| `*: *statuses[].id: extra` | Statuses are columns and have ids (used by the status paths). |
| `*: *statuses[].icon: extra` | Since 2.9.0: a status carries its column's icon (one emoji or an icon id), or null. |
| `*: *.status.icon: extra` | Since 2.9.0: a task's status carries its column's icon too. |
| `*: *statuses[].humans_only: extra` | Since 2.16.0 (spec 234): a status says whether only people may move tasks into it. |
| `*: *.status.humans_only: extra` | Since 2.16.0 (spec 234): a task's status says it too. |
| `*: *.revision: extra` | Since 2.16.0 (spec 234): a task carries its optimistic-lock revision (If-Match / base_revision). |
| `*: *.number: extra` | Since 2.10.0 (spec 214): a task carries its readable number (#123), or null without task numbers. |
| `getfolderlesslists: .lists[].statuses: extra` | Each list carries its statuses (columns). |
| `*: .archived: extra` | Boards say whether they are archived. |
| `*: .override_statuses: extra` | Every board has its own statuses. |
| `*tasklink: .task.*: extra` | A link answers the whole task, not ClickUp's short form. |
| `getfolderlesslists: .lists[].task_count: number, not string` | ClickUp's schema says string; its list example and this API use a number. |
| `getfilteredteamtasks: .last_page: extra` | ClickUp's example of this page omits last_page, which its list page has. |
| `getfilteredteamtasks: .tasks[].*: extra` | ClickUp's example of this page is shorter than its list page's. |
| `getfilteredteamtasks: .tasks[].priority: object, not number` | ClickUp's example of this page shows a number; its list page an object, as here. |
| `gettaskcomments: .comments[].reply_count: number, not string` | A count is a number here. |
| `createatimeentry: *` | ClickUp's published example for this page is the request; this API answers {"data": entry} as the list does. |
| `gettimeentrieswithinadaterange: .data[].*: missing` | Entries carry the host's fields: no tags, source, location or task URL; the person is {id}. |
| `gettimeentrieswithinadaterange: .data[].*: string, not number` | Start and end are millisecond strings, as every other date here. |
| `getspace: .*: extra` | ClickUp's example of one space is shorter than its list of spaces; both are answered alike here. |
| `*: *.checklists[].creator: missing` | A checklist has no row of its own, so no creator. |
| `*: *.checklists[].orderindex: number, not string` | ClickUp's task examples show a string, its checklist examples a number, as here. |
| `getfilteredteamtasks: .tasks[].time_estimate: string, not number` | ClickUp's example of this page shows a number; its task examples a string, as here. |
| `setcustomfieldvalue: .: object, not list` | Custom fields are read-only: 409 FIELD_001 (owner). |

### Other differences

- Ids are strings: the bundle's UUIDs, and the host's ids for people.
- Dates are Unix milliseconds as strings, at the precision the database keeps (seconds).
- No folders, group assignees, points, task URL or custom task id (`null` or empty).
- Checklists are subtasks tagged `checklist:<name>`; a checklist's id encodes its task and name.
- Tags have no colours (`tag_fg`, `tag_bg` are null).
- `GET /v2/task/{id}/member` lists the task's members, not everyone who can open it.
- Dependencies stay within a board; `Related` and `Duplicates` read as `linked_tasks`.
- Task order defaults to `created` ascending; `reverse=true` flips it.
- `priority: null` reads as normal: every task has a priority.
- Deleting a checklist or an item archives it.
- ClickUp's structured comment fields (`notify_all`, `assignee`, `group_assignee`) are not read; mentions come from the text, as in the UI.
- A task without a column, on a board that has columns, goes to the first column on any update (the task form does the same). Tasks get a column when created, so this concerns only rows made outside the bundle.
- **Deleting a board takes several calls** when it holds many tasks: `DELETE /v2/list/{id}` answers 202 with `Retry-After` until it is done, then 200. A ClickUp client expects one call; it must repeat while it gets 202.
- **Time logged without a task** (`POST /v2/team/{id}/time_entries` with no `tid`) means what the host's time store makes of it; the bundle checks only the team and the level.
- Personal tokens expire (the host's rule; Beacon: 90 days by default, 1 year at most); ClickUp's never do.
- Columns are managed on this API's own `/v2/list/{id}/status…` paths, which ClickUp has not.

## Known differences from the UI

Task text is returned raw. A description or comment that embeds a host file (in Beacon, a `beacon-file:` image) shows an API reader the image's alt text and its UUID, which the UI hides from a viewer who may not open the file. The file's bytes stay behind the host's download check. Wiki and document references are not resolved in task text, so they show nothing more.

## Material for a host's manual

What a person using tokens needs to know, for a host to put in its own words (Beacon's manual, English and Spanish):

- **What a token is.** A personal secret for scripts and agents. It acts as the person, never with more than the person may do, and only where the token says. Losing access to a project or organization takes effect on the next request.
- **Levels.**

| Level | May | May not |
|---|---|---|
| *My tasks* | Read the tasks it created or is assigned; change their status, dates and description; comment; manage their checklists; log its own time | Create tasks, reassign, see anyone else's tasks |
| *Read* | Read every task, board, comment, member, tag, field and time entry the person can see | Change anything, even its own tasks |
| *Tasks* | Everything above, plus create, edit, assign, tag, link, attach files to and delete tasks, where the person may | Manage boards or columns |
| *Full management* | Everything above, plus create, rename and delete boards, and manage their columns, where the person may manage the board | Reach provider connections, tokens, billing or roles |

- **Where.** Everything the person reaches, or a list of projects, organizations or boards. Naming a board reaches that board only; creating a board needs its whole project or organization.
- **What is never possible.** Deleting tracked time; reaching provider connections or their tokens; anything the person cannot do in the app.
- **Examples** (the token is a placeholder):

```bash
curl -H "Authorization: bt_placeholder" https://beacon.example/api/tasks/v2/team
curl -H "Authorization: bt_placeholder" "https://beacon.example/api/tasks/v2/list/LIST_ID/task?statuses[]=doing"
curl -X PUT -H "Authorization: bt_placeholder" -H "Content-Type: application/json" -H "Idempotency-Key: move-1" \
     -d '{"status": "Done"}' https://beacon.example/api/tasks/v2/task/TASK_ID
```

- **When something is refused.** 404 means "not there, or not yours to see"; 403 names what is missing (`TOKEN_001` the level, `ACCESS_002` the person's right, `PLAN_001` the plan); 429 means slow down. A retry with the same `Idempotency-Key` never does a write twice.
- **Coming from ClickUp.** Change the base URL and the token. Ids are strings, folders do not exist, columns are statuses, and the rest is in *Differences from ClickUp*.
