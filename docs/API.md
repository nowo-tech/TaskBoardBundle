# Task API (since 2.2, in progress)

A REST API shaped like ClickUp API v2, for scripts and agents with a personal token (spec 213). **Audit status: re-verification pending.** The read endpoints are in; writes arrive before 2.2.0 is tagged, along with the OpenAPI document.

## Read endpoints

Paths after `api.prefix`. *Level* is the least a token needs; the person's access and where still apply.

| Endpoint | Level | Notes |
|---|---|---|
| `GET /v2/user`, `GET /v2/team` | My tasks | A team lists no members |
| `GET /v2/team/{team_id}/space`, `GET /v2/space/{id}` | My tasks | Spaces where reaches and whose plan holds the module |
| `GET /v2/space/{id}/folder` | My tasks | Always `{"folders": []}`: every board is a folderless list |
| `GET /v2/space/{id}/list`, `GET /v2/list/{id}` | My tasks | `task_count` counts only what the token may read; columns are `statuses` (first `open`, last `closed`, the rest `custom`) |
| `GET /v2/list/{id}/task`, `GET /v2/team/{team_id}/task` | My tasks (its own) | 100 per page from `page=0`, `last_page`; `archived`, `subtasks`, `include_closed`, `statuses[]`, `assignees[]`, `tags[]`, `due_date_gt`/`_lt`, `date_created_*`, `date_updated_*`, `date_done_*`, `order_by` (`id`, `created`, `updated`, `due_date`), `reverse`, `include_markdown_description`; on a team also `space_ids[]`, `list_ids[]` |
| `GET /v2/task/{id}` | My tasks (its own) | `include_subtasks`, `include_markdown_description` |
| `GET /v2/task/{id}/comment` | My tasks (its own) | 25 per page, newest first; `start` and `start_id` of the oldest one shown give the next page |
| `GET /v2/task/{id}/member` | Read | Everyone attached to the task |
| `GET /v2/space/{id}/tag` | Read | Tags of the open tasks the token reaches |
| `GET /v2/list/{id}/field` | Read | Custom fields a provider sync imported; none otherwise |
| `GET /v2/team/{team_id}/time_entries` | My tasks | The host's time store answers; `start_date`, `end_date`, `assignee`, `task_id` |

A malformed filter value is 400 `INPUT_002`. Unknown query parameters are ignored on reads. Each list answers in the same number of queries whatever the page holds (9 for a list's tasks, measured at 25 and 100 tasks).

## Differences from ClickUp (so far)

- Ids are strings: the bundle's UUIDs, and the host's ids for people.
- Dates are Unix milliseconds as strings, at the precision the database keeps (seconds).
- No folders, group assignees, points, task URL or custom task id (`null` or empty).
- Checklists are subtasks tagged `checklist:<name>`; a checklist's id encodes its task and name.
- Tags have no colours (`tag_fg`, `tag_bg` are null).
- `GET /v2/task/{id}/member` lists the task's members, not everyone who can open it.
- Dependencies stay within a board; `Related` and `Duplicates` read as `linked_tasks`.
- Task order defaults to `created` ascending; `reverse=true` flips it.

## Off by default

With `api.enabled: false` a host gets no route, service, listener, parameter or table.

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

A listener on `TaskBoardEvents::API_WRITE` (`TaskApiWriteEvent`) audits each write.

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

**Where.** `TaskApiWhere::limitedTo($tenantKeys, $boardIds)`: each entry grants on its own. A named tenant reaches all its boards; a named board is reached even when its tenant is not listed. Nothing else widens: the person's access, the workspace hook and the tenant check apply to it too.

**Tenancy.** With `tenancy.enabled`, a board is reached only when the bundle's tenant resolver opens its tenant for the person (the slug being the key), as the UI asks of the tenant in its URL, whatever the workspace hook answers. A board without a tenant is not served then, as in the UI.

**Notes for hosts.** The security-user check compares ids, not classes: a firewall user of another class with the same id would pass, so use one user class for the API's firewall. An `access_control` refusal on the API's path answers 403 `ACCESS_002`, not 401: add none there. A token whose user the access checker refuses answers 401 like a bad token and counts as a failed token.

## Errors

`{"err": "...", "ECODE": "..."}`, with `OAUTH_017` (401, no header), `OAUTH_019` (401, token not valid, one answer for every reason), `API_001` (404, the instance switch), `RATE_001` and `RATE_002` (429), and `SERVER_001` (500, no detail). Answers carry `X-RateLimit-Limit`, `-Remaining`, `-Reset` (Unix seconds) and `Cache-Control: no-store, private`. The credential is read only from the `Authorization` header, with or without `Bearer`, and never logged.

## Known differences from the UI

Task text is returned raw. A description or comment that embeds a host file (in Beacon, a `beacon-file:` image) shows an API reader the image's alt text and its UUID, which the UI hides from a viewer who may not open the file. The file's bytes stay behind the host's download check. Wiki and document references are not resolved in task text, so they show nothing more.
