# Task API (since 2.2, in progress)

A REST API shaped like ClickUp API v2, for scripts and agents with a personal token (spec 213). **Audit status: re-verification pending.** So far only the skeleton exists: `GET /v2/user` and `GET /v2/team`. The rest arrives before 2.2.0 is tagged, along with the OpenAPI document and the list of differences from ClickUp.

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

## Errors

`{"err": "...", "ECODE": "..."}`, with `OAUTH_017` (401, no header), `OAUTH_019` (401, token not valid, one answer for every reason), `API_001` (404, the instance switch), `RATE_001` and `RATE_002` (429), and `SERVER_001` (500, no detail). Answers carry `X-RateLimit-Limit`, `-Remaining`, `-Reset` (Unix seconds) and `Cache-Control: no-store, private`. The credential is read only from the `Authorization` header, with or without `Bearer`, and never logged.

## Known differences from the UI

Task text is returned raw: an API reader sees the alt text and id of an inline image the UI hides. The file itself stays behind the host's download check.
