# ClickUp OAuth connection and guided import: design

Status 2026-11-25: **design only, nothing built.** For symfony-beacon decision **D-20261124-07** ("Connect to ClickUp"
by OAuth and a guided ClickUp import). Target: the 3.x line of TaskBoardBundle (3.0.0 is on `main`; the 2.x maintenance
branch gets nothing of this). The owner answers the questions in [§24](#24-questions-for-the-owner-q-cu) before the
first build task starts; until then every recommended answer is the working assumption.

## Table of contents

- [1. Summary](#1-summary)
- [2. What already exists (1.18 – 3.0)](#2-what-already-exists-118--30)
- [3. Goals and non-goals](#3-goals-and-non-goals)
- [4. ClickUp facts this design relies on](#4-clickup-facts-this-design-relies-on)
- [5. Who owns the OAuth connection](#5-who-owns-the-oauth-connection)
- [6. Token storage and encryption](#6-token-storage-and-encryption)
- [7. Scopes and workspace authorisation](#7-scopes-and-workspace-authorisation)
- [8. Guided import wizard (UX)](#8-guided-import-wizard-ux)
- [9. Import plans: several boards in one run](#9-import-plans-several-boards-in-one-run)
- [10. Mapping of every ClickUp entity](#10-mapping-of-every-clickup-entity)
- [11. Custom fields: synced or native](#11-custom-fields-synced-or-native)
- [12. Idempotency and re-import](#12-idempotency-and-re-import)
- [13. Rate limits and backoff](#13-rate-limits-and-backoff)
- [14. Partial failure and resume](#14-partial-failure-and-resume)
- [15. Tenancy and authorisation](#15-tenancy-and-authorisation)
- [16. Personal data (GDPR / LOPDGDD)](#16-personal-data-gdpr--lopdgdd)
- [17. SSRF and outbound rules](#17-ssrf-and-outbound-rules)
- [18. Worker mode (FrankenPHP) and timeouts](#18-worker-mode-frankenphp-and-timeouts)
- [19. Concurrency (REQ-RUNTIME-003)](#19-concurrency-req-runtime-003)
- [20. Architecture (REQ-ARCH-001, private entity manager)](#20-architecture-req-arch-001-private-entity-manager)
- [21. Threat model](#21-threat-model)
- [22. Testing strategy](#22-testing-strategy)
- [23. Phasing, versions and Beacon integration](#23-phasing-versions-and-beacon-integration)
- [24. Questions for the owner (Q-CU)](#24-questions-for-the-owner-q-cu)

## 1. Summary

TaskBoard already **imports and syncs ClickUp through API v2** (since 1.23, see [sync.md](../sync.md)): one board follows
one list, folder or space; statuses, people, custom fields, comments, checklists, attachments, time entries and the
mass-archive guard are all in place. What a host cannot do today:

1. **Connect without pasting a personal token.** The bundle asks the host for a `ProviderCredential`, and every host so
   far (Beacon `TaskProviderToken`) stores a pasted `pk_…` token. D-20261124-07 wants "Connect to ClickUp" by OAuth,
   with the ClickUp app registered once per instance.
2. **Import a whole workspace in one guided pass.** Today a manager links one board at a time from that board's
   connection page. The request wants a wizard that starts from the workspace, picks spaces / folders / lists, creates or
   chooses the target boards, maps statuses, custom fields and people once, shows a dry-run, and runs everything as one
   resumable background job with a summary.
3. **Get editable fields.** Synced custom fields are read-only (409 `FIELD_001` on the API). Since 2.19 boards have native
   custom fields (text, number, dropdown); the wizard should be able to land ClickUp fields there.

The design keeps the 1.23 rule — **the bundle never stores a provider token by default** — and adds:

- an **OAuth credential scheme** (`ProviderCredential::$options['scheme'] = 'oauth'`, sent as `Authorization: Bearer`)
  and a stateless **`ClickUpOAuthClient`** helper (authorize URL, code exchange, authorised workspaces) that a host calls
  from **its own** routes, the MailKit Q-254-15 pattern ("host-owned connection, no bundle callback");
- an **import plan** layer (`TaskSyncImportPlan`) that drives several existing board connections as one job;
- a **wizard** (UiKit / FormKit dialogs) on top of the existing connection, status-map and People steps;
- a **native field mapping** option for the three native field types;
- a shared **per-token rate budget**, because several boards now use one token at once.

Phase 1 is a one-shot import (with idempotent manual re-import). Phase 2 is incremental sync on a schedule and ClickUp
webhooks.

## 2. What already exists (1.18 – 3.0)

| Piece | Where | Reused as |
| --- | --- | --- |
| File imports (ClickUp CSV / JSON, Trello, Jira, TaskBoard) | `src/Import/*`, `TaskImportOrchestrator`, `ImportedTaskUpdater`, `nowo:task-board:import`, `docs/import/clickup.md` | Unchanged. Same provenance key, so a file-imported board is adopted without duplicates |
| Provenance | `task_board_task_links.import_source` + `external_id` (index `idx_task_board_task_links_import`), `DoctrineTaskImportLinkLookup`, `TaskImportLinkFinderGate` | The idempotency key (`clickup`, task id) |
| ClickUp adapter | `Sync\Provider\ClickUp\ClickUpTaskProvider` (`BASE_URI` constant `https://api.clickup.com/api/v2/`, raw `Authorization` header), `ClickUpCustomFieldParser`, `ClickUpCommentRenderer` | Gains the `oauth` scheme (Bearer) and two read calls (`/user`, `/team` already used for people) |
| HTTP client | `Sync\Http\ProviderHttpClient`: no redirects, 429 with `Retry-After` / `X-RateLimit-Reset`, `X-RateLimit-Remaining: 0` waits, size caps | Gains a shared budget per connection (§13) |
| Run engine | `TaskBoardSyncService` (`link`, `runLinked`, `run`, `unlink`, `requestCancel`), board run lock with heartbeat, slices, cursor, `paused` / `rate_limited`, history, dry-run | One plan item = one existing board run |
| Sources | `SourceProviderInterface` (list, folder `f:<id>`, space `s:<id>`), `TaskSyncSourceScanner` | The wizard's tree and pre-scan |
| Status map | `link(..., $statusColumnMap)`: column id, `__create`, `__skip` | The wizard's status step |
| People directory | `TaskSyncDirectory` / `TaskSyncPerson`, `SyncPersonDirectoryInterface::directoryScope()`, People page, `TaskSyncPeopleReassigner`, "a mapping never grants access" | The wizard's people step (one directory per tenant + workspace) |
| Typed synced fields | `task_board_sync_fields`, `task_board_sync_field_values`, `sync.custom_fields.mode` | Default for fields not mapped to native ones |
| Native custom fields | `TaskCustomField`, `TaskCustomFieldValue`, `Enum\CustomFieldType` (`text`, `number`, `dropdown`; `apiType()` already speaks ClickUp names) | Target of the native mapping (§11) |
| Files | `RemoteFileFetcher` (attachment host allow-list, `NoPrivateNetworkHttpClient`, optional OutboundUrlGuard bridge), `SyncedFileStorageInterface` | Unchanged |
| Time | `ImportedTimeEntryReceiverInterface` / `OwnerlessTimeEntryReceiverInterface` (host writes TimeTrack) | Unchanged |
| Background | `SyncRunnerInterface` (`InlineSyncRunner` default; the host dispatches `SyncRequest` ids to Messenger) | Plan runner (§9.4) |
| Access | `TaskBoardResourceAccessChecker::canManageBoardConnection()` / `canImportTasks()`, `BoardAccessCheckEvent::ACTION_MANAGE_CONNECTION` / `ACTION_IMPORT_TASKS`, `TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE` | Plan authorisation (§15) |
| Host credential port | `Sync\Hook\ProviderCredentialSourceInterface` (`connectionsFor`, `credentialFor`, `credentialFormUrl`) + `ConnectionChoice`, gate `Port\ProviderCredentialSourceGate` | Stays the only way a token reaches the bundle (§5) |

Beacon's side today: `App\Tasks\Domain\TaskProviderToken` (Halite-encrypted via DoctrineEncrypt, per tenant, last four
only), `ProjectProviderTokens` (the credential source), `TaskProviderTokenController` (paste form),
`TaskProviderTokensEraser` (erasure), `SyncTaskBoardHandler` (Messenger).

## 3. Goals and non-goals

**Goals**

- G1. A host can offer *Connect to ClickUp* (OAuth 2 authorization code) with an instance-wide ClickUp app, and the
  resulting connection works everywhere a pasted token works today (picker, list sync, console, worker).
- G2. A guided import from a ClickUp workspace into one or more boards of one tenant: connect → choose → map → preview
  → run in the background with progress → summary. Accessible without JavaScript for the decisive steps (existing
  connection page rule) and with axe-clean dialogs.
- G3. Re-running the same import never duplicates a board, a column, a task, a comment, a field, a person or a file.
- G4. A run of hours (100 requests per minute) survives worker restarts, deploys, rate limits and failures, and resumes
  where it stopped.
- G5. No new place where a ClickUp secret can leak: not in the bundle's tables (unless the host opts in, §6.3), logs,
  events, Messenger messages, caches, sessions, exceptions or the UI.
- G6. Same access model as the rest of TaskBoard (REQ-UI-002 house pattern: access events, deny by default where the
  data is sensitive).

**Non-goals (phase 1)**

- Writing back to ClickUp (two-way sync). The OAuth app is used read-only by the bundle (§7).
- Webhooks and scheduled incremental sync (phase 2, §23).
- Importing ClickUp Docs, Whiteboards, Goals, Dashboards, Chat, Forms, Sprints, reminders (not in the v2 task API or not
  task data; see the existing `apiGaps()` list).
- A bundle-owned OAuth callback route (rejected in §5, unless the owner picks option B).
- Other providers' OAuth (Jira, Trello, Asana). The contracts stay provider-neutral so they can follow.
- Automatic user creation. Unknown people stay external or are invited through the existing `PeopleInvitationInterface`.

## 4. ClickUp facts this design relies on

Read from ClickUp's developer docs on 2026-11-25 (`developer.clickup.com/docs/authentication`, `/docs/rate-limits`,
`/docs/webhooks`, `/reference/getaccesstoken`). Items marked **TO CONFIRM** are checked against a real (owner-provided)
test workspace in build task 1, with synthetic data only.

| Topic | Fact | Consequence |
| --- | --- | --- |
| Authorize URL | `https://app.clickup.com/api?client_id=…&redirect_uri=…&state=…` (`state` optional, supported) | Constant in the helper; `state` always sent |
| PKCE | **Not documented / not supported** | No `code_challenge`; protection = confidential client (server-side secret) + single-use `state` bound to session, actor, tenant, purpose (§21 T3). TimeTrack's `CalendarPkce` does not apply |
| Token exchange | `POST https://api.clickup.com/api/v2/oauth/token`, body `client_id`, `client_secret`, `code` (JSON or form) → `{"access_token": "…"}` | Body, never the query string (keeps the secret out of access logs) |
| Token lifetime | "Currently does not expire", **subject to change**; no refresh token, no `expires_in` | No refresher now; the contract keeps optional `expiresAt` / refresh fields so a future change is a minor |
| Revocation | No documented revoke endpoint; the user removes the app from ClickUp's app settings | *Disconnect* deletes the stored token and tells the user where to remove the app in ClickUp |
| Header | OAuth: `Authorization: Bearer <token>`; personal token (`pk_…`): `Authorization: <token>` | `ProviderCredential` carries `scheme`; the adapter builds the header |
| Scopes | **None.** The user picks one or more **workspaces** on ClickUp's consent screen; the token can do whatever that user can do in them (read and write) | Read-only is a bundle rule, not a token property (§7) |
| Authorised workspaces | `GET /team` ("Get Authorized Workspaces"); `GET /user` (the authorising user) | Connection label and workspace picker |
| App creation | Only workspace owners / admins create OAuth apps; redirect URL registered with the app (exact URL vs domain **TO CONFIRM**) | Beacon registers one app per instance; dev uses a second app (§23) |
| Hierarchy | Workspace (`team`) → Space → Folder (optional, may be hidden) → List → Task → subtasks (any depth, `parent`) + checklists | Plan tree (§8.3) |
| Rate limits | **Per token**, by the plan of the token's workspace: 100 / min (Free, Unlimited, Business), 1 000 (Business Plus), 10 000 (Enterprise); headers `X-RateLimit-Limit`, `-Remaining`, `-Reset` (Unix time); 429 | Shared budget per connection (§13) |
| Webhooks | Created with a user token on a space, folder, list or task; events `taskCreated`, `taskUpdated`, `taskDeleted`, `taskStatusUpdated`, `taskMoved`, `taskComment*`, `taskTimeTrackedUpdated`, list / folder / space events; one secret per webhook; signature header and algorithm (`X-Signature`, HMAC-SHA256 of the raw body) **TO CONFIRM**; whether OAuth tokens may create them **TO CONFIRM** | Phase 2 only |

## 5. Who owns the OAuth connection

### 5.1 Options

| | A. Host-owned connection, bundle helper (recommended) | B. Bundle-owned flow and storage (CrmKit H3 pattern) | C. Host-owned by default, bundle store opt-in |
| --- | --- | --- | --- |
| Callback route | Host's (Beacon already has `TaskProviderTokenController`) | Bundle route `…/sync/clickup/oauth/callback` | Host's |
| Who calls ClickUp's token endpoint | Host, through the bundle's stateless `ClickUpOAuthClient` | Bundle | Host, through the helper |
| Where the token lives | Host table (Beacon: `TaskProviderToken`, Halite) | New bundle table, sealed by a `SecretBox` port | Host table, or bundle table when `sync.credential_store: bundle` |
| App client id / secret | Host (instance settings) passes them to the helper per call | `ClickUpOAuthAppInterface` port (runtime setting) | Host, or the port when the bundle store is on |
| Fits the 1.23 rule "the bundle never stores a token" | **Yes** | No (rule changes in a minor) | Yes by default |
| Fits MailKit Q-254-15 (host-owned, no bundle callback) | **Yes** | No | Yes by default |
| Work for a new host | Host writes a controller (≈ 60 lines, documented recipe) and a token table | None beyond config | As A, or none |
| Attack surface in the bundle | Smallest (no route, no secret at rest) | Route + state store + crypto + key rotation | Largest (both paths to maintain and audit) |
| Effort | S | L | L |

### 5.2 Recommendation: A

- The bundle ships **`Nowo\TaskBoardBundle\Sync\OAuth\ClickUpOAuthClient`** (framework-free service, no route, no state):
  - `authorizationUrl(string $clientId, string $redirectUri, OAuthState $state): string` — constant base, validates the
    redirect URI (https, no user info, no fragment; `http://localhost` / `127.0.0.1` only when `kernel.debug`).
  - `exchange(string $clientId, #[\SensitiveParameter] string $clientSecret, #[\SensitiveParameter] string $code, string $redirectUri): OAuthGrant`
    — `POST` to the constant token URL through `ProviderHttpClient` (no redirects, 10 s, 16 KiB cap); `OAuthGrant` holds
    the token like `ProviderCredential` (redacted dump, serialization throws) plus optional `expiresAt` / `refresh`
    (null today).
  - `describe(ProviderCredential $credential): OAuthIdentity` — `GET /user` and `GET /team`: user id, display name,
    workspaces (id, name). Never the e-mail into the label.
- **`OAuthState`** value object: 32 random bytes, base64url (43 characters), `/D` regex validation, constant-time
  compare. Same shape as TimeTrack's `CalendarOAuthState`, but TaskBoard does not depend on TimeTrack: either a copy in
  TaskBoard or a move to a tiny shared package (Q-CU-3).
- `ProviderCredential::$options['scheme']` becomes a documented value: `personal` (default, raw header) or `oauth`
  (`Bearer`). The adapter refuses any other value. `ConnectionChoice` gains an optional `kind` (`personal` / `oauth`) and
  `workspaceIds` (list, may be empty) so the wizard can filter connections by workspace without a call.
- `ProviderCredentialSourceInterface::credentialFormUrl()` keeps its meaning; a new optional interface
  **`OAuthConnectionStartInterface::connectUrl(TaskBoardSnapshot|TenantRef $scope, string $provider, string $returnTo): ?string`**
  lets the wizard show *Connect to ClickUp* (the host's start route) next to *Paste a token*. The bundle validates that
  `returnTo` is a bundle route of the same tenant before handing it over (no open redirect).
- `docs/sync.md` gets a host recipe: start route (store `state` + actor + tenant + purpose + `returnTo` in the session,
  TTL 600 s, single use), callback route (check `error`, `state`, the actor still allowed; exchange; describe; encrypt;
  store one connection per (tenant, ClickUp user, app); redirect to `returnTo`).

Why not B: the only gain is less host code, and Beacon already owns encrypted provider tokens, erasure and the paste
form; moving tokens into the bundle would add a second secret store, key rotation and an OAuth route to audit, and break
the 1.23 promise every current host relies on. C stays possible later as a pure addition (Q-CU-1).

## 6. Token storage and encryption

### 6.1 Host side (option A)

- **Encrypted at rest** with the host's mechanism. Beacon: the existing `TaskProviderToken` row (Halite via
  DoctrineEncrypt) gains `kind` (`personal` / `oauth`), `remote_user_id`, `remote_user_name`, `workspace_ids` (JSON),
  `app_key` (which ClickUp app issued it). Only the last four characters are shown.
- **One OAuth connection per (tenant, ClickUp user, app)**: reconnecting replaces the token (`replaceToken()`), so the
  boards that use it keep working (G1).
- **Client secret** of the ClickUp app: an instance runtime setting (house rule *Runtime settings env/DB*: env by
  default, DB switchable, encrypted with InstanceSettings' sealer). Never per tenant in phase 1 (Q-CU-2).
- The plaintext exists only in `credentialFor()`'s `ProviderCredential` for one run slice.

### 6.2 Bundle side

- The bundle **stores no token and no client secret**. It stores the opaque `connection_ref` (existing column,
  191 characters) and, new, the workspace id per plan.
- Messenger messages carry ids only (plan id, item id, actor id); `ProviderCredential` and `OAuthGrant` throw on
  serialization, so a mistake fails loudly in tests.
- Exceptions from the OAuth client carry a code (`oauth_denied`, `oauth_state_invalid`, `oauth_exchange_failed`,
  `oauth_token_rejected`) and the HTTP status, never the response body (it could echo the code or secret).

### 6.3 Optional bundle store (only if the owner picks C)

`sync.credential_store: host` (default) | `bundle`. With `bundle`: table `<p>sync_credentials` (`ref` uuid, `tenant_key`,
`provider`, `kind`, `label`, `last_four`, `sealed` text, `key_id`, `remote_user_id`, `workspace_ids`, timestamps,
`version`), sealed by a required `SyncSecretBoxInterface` (sodium `crypto_aead_xchacha20poly1305_ietf` with AAD =
purpose + tenant + ref, keys outside the DB, rotation command; DoctrineEncrypt bridge for hosts that prefer it), the
same shape CrmKit 0.10 uses. Not planned unless asked.

## 7. Scopes and workspace authorisation

- ClickUp OAuth has no scopes: a token can **write** wherever its user can. TaskBoard's ClickUp adapter only issues
  `GET` requests (enforced in `ProviderHttpClient` for the ClickUp provider: any other method throws, unit-tested), and
  phase 2 webhooks are the only planned write (create / delete a webhook), behind its own option.
- The consent screen lets the user tick workspaces. The connection records the workspaces `GET /team` returns; the
  wizard only offers those. A workspace removed in ClickUp later shows *Not authorised any more — reconnect*.
- The connection's power equals its user's ClickUp visibility. The wizard says so before *Connect* ("TaskBoard will read
  everything you can see in the workspaces you choose; it never changes anything in ClickUp").
- Guest users can authorise too; their token sees only shared items, and the pre-scan's counts show it. Time entries of
  others need an owner / admin token (existing `TimeAccessMode`).

## 8. Guided import wizard (UX)

UiKit dialogs and FormKit forms; every step is a full page without JavaScript (POST + redirect) and an enhanced dialog
with it, like the connection page today. Step state lives in a draft `TaskSyncImportPlan` row (§9), not in the session,
so a reload, a second tab or another manager sees the same draft. Each step's POST has its own CSRF token id
(`task_board_import_plan_<step>`) and checks the plan's `version` (stale step → *This import was changed elsewhere*,
reload).

Entry points: the index page (*Import from ClickUp*, managers of the tenant only), a board's connection page (*Import more
lists*), and the host (route `task_board_import_plan_new` with a tenant).

### 8.1 Connect

- Lists the tenant's ClickUp connections (`connectionsFor()`), labelled "ClickUp · <user name> · …a1b2 · OAuth".
- Buttons: *Connect to ClickUp* (host's `connectUrl()`, when offered), *Paste a token* (host's `credentialFormUrl()`).
- After connecting, the host returns to this step with the new connection preselected.

### 8.2 Workspace

- One radio per authorised workspace (`describe()`); one workspace per plan (keeps the people directory and the rate
  budget simple).
- Shows the token's role (owner / admin / member / guest) and what that means for time entries.

### 8.3 Choose what to import

- A tree Space → Folder → List (existing container browser: APG disclosure + radio/checkbox groups, lazy levels, polite
  live region). Checkboxes at every level; a checked space or folder means "its lists now", not "future lists" (phase 2
  decides future lists).
- **Board layout** (radio):
  - *One board per list* (default; board name = list name, folder and space in the board description);
  - *One board per folder* (lists of the folder become one board; each task gets the tag `list:<name>`, the existing
    `sync.list_as_tag`);
  - *One board for everything* (all chosen lists into one board, tags as above).
- **Target per board**: *New board* (default; name editable, tenant folder optional) or *Existing board* (a select of the
  tenant's boards the actor may import into, §15). An existing board already linked to another source is refused.
- Options: closed tasks (default on), archived tasks (`sync.import_archived`, default on), subtasks, checklists,
  comments, attachments, time entries (only if the host has a sink), custom fields.

### 8.4 Statuses → columns

- One table per target board: each ClickUp status (with its type and colour) → a column select: existing columns,
  *Create column "<status>"* (`__create`, default when no same-named column), *Do not import* (`__skip`). Same-named
  columns are preselected (existing matching rule: case, accents, spaces ignored).
- Boards with the same status set share one table (*Apply to all boards with these statuses*).
- *Columns no status uses*: keep (default) / delete when empty (existing `unmatchedColumns`).

### 8.5 Custom fields

- One row per ClickUp field of the chosen lists (name, type, how many lists use it): *Synced, read-only* (default for all
  types), *Native field* (only for text, short text, number, drop-down, §11), *Do not import*.
- For *Native field*: *Create "<name>"* or an existing native field of the target board with a compatible type.

### 8.6 People

- The existing People step (`TaskSyncPeopleScanner`, ≤ 15 calls) on the plan's directory (scope `tenant:<key>:clickup:<workspace>`,
  Q-CU-6). Verified e-mail matches are automatic; unverified ones are suggestions a manager must confirm; nobody is
  mapped until *Save*; a mapping never grants board access; *Invite by e-mail* when the host offers it; *Mark all
  undecided as former people*.

### 8.7 Preview (dry run)

- Counts per target board **from the provider, no writes**: tasks (open / closed / archived), subtasks, checklist items,
  comments (estimated from the first pages), attachments and their declared size, custom field values, people (mapped /
  suggested / external), time entries (window count), columns to create, boards to create.
- **Estimated duration** from the request count and the token's `X-RateLimit-Limit` ("about 2 h 10 min at 100
  requests per minute"), and the host's quota answers (`TaskBoardQuotaCheckEvent` for boards, `SyncQuotaPolicyInterface`
  for tasks): a refusal is shown here, before anything is written.
- Uses the existing `--dry-run` engine per item with `maxPages` capped (`plan.preview_max_pages`, default 3 per list);
  beyond that the counts are marked *estimated*.

### 8.8 Run

- *Start import* (UiKit confirm dialog, summarising boards and estimated duration). The plan becomes `queued`; the runner
  dispatches it (§9.4). The page shows a `<progress>` per board and overall, polled every 5 s from a JSON endpoint
  (`Cache-Control: no-store`, counters only), a polite live region, *Cancel* (POST, confirm), *Leave this page — the
  import continues*.
- A run can be followed from the index (badge *Importing from ClickUp · 42 %*) and from each board's connection badge.

### 8.9 Summary

- Per board: created / updated / unchanged / skipped (status, deleted here, other board of the tenant) / failed; columns
  created; fields; people still undecided (link to the People page); files refused (by reason); the completeness report
  link; warnings (`paging_stopped`, `unmapped_status`, `mass_archive_guard`, `time_member_mode`).
- *Re-import now* (idempotent, §12), *Open board*, *Map people*, *Download report (JSON)* (counts and ids only, no
  personal data beyond what the actor can already see).
- Event `SYNC_IMPORT_PLAN_FINISHED` (ids and counts only) so the host can notify the actor (NotifyKit in Beacon).

## 9. Import plans: several boards in one run

### 9.1 Tables (new, under `sync.enabled`)

| Table | Columns | Keys |
| --- | --- | --- |
| `<p>sync_import_plans` | `id` uuid, `tenant_key` (64, null for no tenancy), `provider` (32), `connection_ref` (191), `workspace_id` (64), `directory_id` FK null, `status` (16: `draft`, `queued`, `running`, `paused`, `rate_limited`, `succeeded`, `failed`, `cancelled`), `layout` (16), `options` JSON (no secret), `field_map` JSON, `created_by_id`, `started_by_id`, `created_at`, `started_at`, `finished_at`, `heartbeat_at`, `cursor` JSON (item index + phase), `report` JSON (counts), `version` (int) | index (`tenant_key`, `status`); partial-unique emulation: one active plan per (`tenant_key`, `provider`, `workspace_id`) enforced by a lock row (§19) |
| `<p>sync_import_plan_items` | `id` uuid, `plan_id` FK cascade, `position`, `source_type` (8), `source_id` (64), `source_label` (255), `source_path` JSON, `board_id` null (set when created or chosen), `board_name` (255), `create_board` bool, `status_map` JSON, `status` (16), `connection_id` null (the existing `TaskBoardConnection` once linked), `report` JSON, `error_code` (64) null | **unique (`plan_id`, `source_type`, `source_id`)**; index (`board_id`) |

Each item, once started, is an ordinary `TaskBoardConnection` (existing table) with `options.mode = snapshot` (phase 1:
no scheduled refresh; manual *Re-import* only) — so the board badge, history, People page, completeness report, unlink
and the console all keep working on it.

### 9.2 Services (`Sync\Plan`)

- `ImportPlanService::draft(tenant, actor, provider, connectionRef, workspaceId): PlanId`
- `ImportPlanService::choose(PlanId, list<SourceChoice>, Layout, version)` / `mapStatuses()` / `mapFields()` /
  `options()` — each validates, bumps `version`, rejects stale versions.
- `ImportPlanService::preview(PlanId, actor): PlanPreview` (dry run, §8.7).
- `ImportPlanService::start(PlanId, actor): void` (authorisation and quotas re-checked; status `queued`; dispatch).
- `ImportPlanRunner::runSlice(PlanId, SyncOptions): PlanSliceReport` — one slice: create the next board if needed, link
  it, run its connection's slice (`runLinked()`), move on when it succeeds; returns `isResumable()` and
  `retryAfterSeconds` like `SyncReport`.
- `ImportPlanService::cancel(PlanId, actor)` (sets a flag the current item's `requestCancel()` reads).

### 9.3 Board creation

Boards are created through the bundle's existing creation path (the code behind `board_create`, which dispatches
`BoardCreatedEvent`; columns from the status map, tenant and folder from the plan), one board per unit of work, inside the plan lock, with the item
row updated in the same transaction (`board_id`, `create_board = false`), so a crash between "board created" and "item
updated" cannot create a second board (§19). `TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE` is asked for each board at
that moment as well as in the preview.

### 9.4 Background run

- `sync.plan.runner` (new): `ImportPlanRunnerInterface::dispatch(PlanRunRequest $ids)` + `advancesOnPoll()`, default
  `InlinePlanRunner` (one slice per poll, like `InlineSyncRunner`; fine for demos and small imports).
- The bundle ships an **optional Messenger runner** (`sync.plan.messenger.enabled: false`, REQ-MSG-001 style): message
  `RunImportPlanSlice {planId, actorId}` (ids only), handler resolves the actor through `SyncActorProviderInterface`
  (stops when null), runs one slice of at most `slice_seconds` (default 120 s, below the worker's timeout), and
  re-dispatches with `DelayStamp(retryAfterSeconds * 1000)` while resumable. Beacon may keep its own handler (Q-CU-9).

## 10. Mapping of every ClickUp entity

"Existing" = what the 1.23 – 3.0 sync already does and the plan reuses; "new" = this design.

| ClickUp | TaskBoard | Notes |
| --- | --- | --- |
| Workspace (`team`) | Plan `workspace_id`; people directory scope | New: one per plan |
| Space | Board (layout *per folder* / *all*) or only the board description and `location` | New layout choice; space statuses feed the status map |
| Folder (incl. hidden) | Board (*per folder*) or description / `location` | Existing `f:<id>` source |
| List | Board (*per list*, default) or tag `list:<name>` | Existing list source and `list_as_tag` |
| Status (+ type, colour, order) | Column (map, `__create`, `__skip`); `closed` / `done` type → completed | Existing |
| Task | `Task` + `TaskLink(import_source = clickup, external_id = id)` + `task_board_sync_items` | Existing; creator and `createdAt` from ClickUp |
| `custom_id` | `task_board_sync_items.custom_id`, searchable | Existing |
| Name / `markdown_description` | Title / description through `RemoteContentSanitizer`; images stored and rewritten | Existing |
| Priority (urgent, high, normal, low) | Four priorities | Existing |
| Start / due (+ has-time) | Start / due dates | Existing (UTC instants) |
| `time_estimate` / per-assignee estimates | Estimate / read-only panel | Existing |
| Points | Read-only panel | Existing |
| Tags (+ colour) | Tags | Existing |
| Assignees / watchers | `TaskMember` through the People mapping (only if the user can view the board) | Existing; no notification during import |
| Creator | Task creator (projected) or actor + person ref | Existing |
| Subtasks (any depth) | Subtasks (`parent`) | Existing (flat with parent) |
| Checklists / items (+ assignee, resolved) | Subtasks tagged `checklist:<name>`, resolved → done column | Existing (`sync.checklists.enabled`) |
| Dependencies (`waiting_on` / `blocking`) | `TaskDependency` (Gantt) | Existing |
| `linked_tasks` | `related` relations | Existing |
| Custom fields | Synced typed fields (default) or native fields (§11) | Native option is new |
| Comments (+ threads, files, assigned, resolved, edit date) | `TaskComment` + `task_board_sync_comments`; author projected or actor + ref | Existing |
| Reactions | Not imported | Existing gap |
| Attachments (task and comment) | Host's `SyncedFileStorageInterface`, links of type `attachment` | Existing (allow-listed hosts, type sniffing, caps) |
| Time entries | Host sink (Beacon → TimeTrack `ImportedTimeEntryWriter`, `externalSource = clickup:<directory>`) | Existing |
| Time in status | Read-only table | Existing (budgeted) |
| Task type (`custom_item_id`) | Read-only panel | Existing |
| Archived tasks | Imported archived (`archive_reason = remote`) | Existing |
| Recurrence | Read-only text (rare in v2 answers) | Existing; TaskBoard recurrence (2.3) not set from ClickUp (Q-CU-11) |
| Multiple lists (TIML) | Imported once, in the first list's board; others noted | Existing `include_multi_list_tasks`; cross-board duplicates refused by the tenant rule |
| Members (`team.members`, role, e-mail, name) | People directory | Existing; no avatar stored |
| Docs, Goals, Sprints, reminders, dashboards, chat | Not imported | `apiGaps()` already reports them |

## 11. Custom fields: synced or native

- **Synced (default):** existing typed `task_board_sync_fields` / `_values`, read-only, provider owns the values,
  refreshed on re-import. Every ClickUp type is kept (unknown ones as text).
- **Native (opt-in per field):** only for ClickUp `text`, `short_text` → `CustomFieldType::Text`; `number` →
  `Number`; `drop_down` → `Dropdown` (options by ClickUp option id, kept in the field's option metadata so a renamed
  option stays the same option; colours kept). `currency`, `labels`, `date`, `checkbox`, `url`, `email`, `phone`,
  `emoji`, progress, `users`, relationships stay synced until the ROADMAP's extra native types exist.
- The native field gets a provenance marker (`TaskCustomField` option `source = clickup:<field id>`, no schema change if
  the options JSON exists; else a nullable `external_ref` column, Q-CU-7), so a re-import finds it again.
- **Re-import rule for native values** — the existing per-field hash rule: the provider's value is written only when
  nobody changed it here since the last import; a local edit wins and is noted as a conflict, never overwritten. An empty
  remote value never clears a local one.
- A drop-down value that is not an option of the native field (option deleted here) is kept as a conflict note, not
  recreated.

## 12. Idempotency and re-import

| Object | Key | Re-import behaviour |
| --- | --- | --- |
| Plan item → board | (`plan_id`, `source_type`, `source_id`) unique; then `connection.board` | Never a second board for the same item; *Re-import* reuses the plan |
| Board ↔ source | Existing one-connection-per-board rule | A board linked to another source is refused in step 8.3 |
| Column | Status map (column id) or `createdColumns` option | Created once; renamed columns are matched by id, not name |
| Task | (`import_source = clickup`, `external_id`) | Update through the field-hash rule (provider wins only for fields untouched here); deleted here → skipped, never recreated; archived remotely → archived here; already imported into another board of the tenant → skipped and counted |
| Comment | `task_board_sync_comments.remote_id` | Imported once; updated if edited in ClickUp and not here |
| Checklist item | `external_id = <task>#<item>` | As tasks |
| Synced field / value | provider field id per board | Upserted; dropped remote values removed |
| Native field / value | `clickup:<field id>` marker | §11 |
| Person | (directory, remote id) | Name / e-mail refreshed; human decisions never overridden |
| File | remote id + size + date | Reused when unchanged |
| Time entry | remote entry id (host sink) | The host's writer is idempotent (TimeTrack `externalSource`) |

*Update vs skip*: the wizard offers **Update changed tasks** (default; the rule above) or **Only add new tasks** (existing
tasks untouched, `SyncOptions::$createOnly`, new flag). There is no "overwrite local edits" option.

A file-imported board (CSV / JSON) chosen as an existing target is adopted without duplicates (existing behaviour).

## 13. Rate limits and backoff

- 100 requests per minute per token on most plans is the bottleneck: a 5 000-task workspace with comments and details is
  roughly 5 000 / 100 pages + 5 000 detail calls + comment calls ≈ 1.5 – 3 h. The preview says so.
- **Shared budget per connection.** Several plan items, a list sync of another board and the People scan may use the
  same token at once. New `ProviderRateBudget` (Symfony RateLimiter, token bucket, storage = `sync.rate_limit.storage`
  service, default the cache pool `cache.app`; key = `taskboard:clickup:` + HMAC of `connection_ref` with `kernel.secret`,
  never the token) sized from the last `X-RateLimit-Limit` seen (default 100, safety margin 10 %). `ProviderHttpClient`
  reserves a token before each call; a reservation that would wait more than `max_inline_wait` ends the slice as
  `rate_limited` with `retryAfterSeconds`.
- 429 handling stays as today (`Retry-After`, else `X-RateLimit-Reset`, else 60 s; at most 5 inline retries), plus
  **full jitter** (0 – 20 %) on the re-dispatch delay so parallel workers do not wake together.
- 5xx and timeouts: exponential backoff 2, 4, 8, 16, 32 s with jitter, at most 5 per call, then the slice ends `paused`
  with `error_code = provider_unavailable`; the plan resumes later (the runner's delay doubles up to 15 min).
- One plan runs its items **sequentially** (one board at a time): parallelism would not beat a per-token limit and would
  complicate the lock order.

## 14. Partial failure and resume

- Unit of work = one task page per board (existing). A page either commits whole or not at all; the cursor advances only
  after commit. Files and comments of a committed page are fetched after it, with their own idempotent keys, so a crash
  repeats at most one page's side reads.
- Plan cursor = (item position, item phase: `create_board` / `link` / `sync` / `done`). Resume after restart, deploy,
  `rate_limited`, `paused` or a worker kill (heartbeat older than 10 min → the lock is taken over, existing rule).
- **Per-item failure does not stop the plan**: a lasting error on one list (403 on that list, board quota refused) marks
  the item `failed` with a code and the plan continues; the summary lists it with *Retry this list*. A **connection-level**
  failure (401 / `OAUTH_019`-like "token invalid", workspace no longer authorised) pauses the whole plan as
  `failed:credential` and the summary asks to reconnect; reconnecting (same connection ref, token replaced) and *Resume*
  continues where it stopped.
- *Cancel*: the current page finishes, nothing is rolled back, the summary says what was imported; a later *Re-import*
  continues idempotently.
- The mass-archive guard and `deletions_skipped` keep applying to each item.

## 15. Tenancy and authorisation

House access pattern (REQ-UI-002: `security.access_roles`, `access_checker`, events with grant / deny / default):

| Action | Check | Default |
| --- | --- | --- |
| See *Import from ClickUp*, create / edit / preview / start / cancel a plan in a tenant | New tenant-level action **`import_plan`** (house pattern: an access-check event with grant / deny / default, next to the existing tenant list / create checks) | **Deny** unless the user has `security.manage_roles` or the host grants it (Beacon: project manager / org admin). Deny by default because it can create boards and reads remote e-mails |
| Use a connection | The host's `connectionsFor()` answer for that user (Beacon: managers of the tenant only) | Host decides |
| Create a board in the plan | Existing board-create permission + `TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE` | Existing |
| Import into an existing board | `canManageBoardConnection()` **and** `canImportTasks()` on that board | Existing (creator or `manage_roles`) |
| People step | `canManageBoardConnection()` on every board of the directory | Existing (else 404) |
| See progress / summary | Whoever may manage the plan; board managers of an item see their board's part | New |

- Every route answers **404** (not 403) when the action is denied, like the connection routes.
- Plans, connections and directories are **tenant-bound**: a plan's boards, its connection (`credentialFor()` resolves
  only within the board's tenant — Beacon already does this) and its directory scope all carry the same `tenant_key`. A
  plan item can never target a board of another tenant (checked at choose and again at start).
- The worker re-checks the actor (`SyncActorProviderInterface`) at every slice: a disabled, erased or demoted user stops
  the plan (`failed:actor`), it never runs as someone else.

## 16. Personal data (GDPR / LOPDGDD)

- **New personal data**: none beyond the existing sync (names and e-mails of ClickUp people, comment texts and authors,
  attachments, time entries, assignee estimates). The OAuth token is personal data of the connecting user (host-held).
- **Minimisation**: no avatars stored; e-mails only in the people directory; the plan, its report JSON, events and logs hold ids and counts only.
- **Information to the connecting user** (wizard step 8.1): what is read, that ClickUp members' names / e-mails and
  comments are copied into the tenant, that nothing is written back, and where to revoke the app in ClickUp. Beacon adds
  the processing to its records (ClickUp as source, the tenant as controller).
- **Rights**: existing `TaskSyncPersonRepository::exportFor()`, `anonymise()`, `detachUser()` (erasure order in
  UPGRADING), `pruneDirectory()` on last unlink; new: deleting a plan deletes its draft data (choices, maps) and never the
  imported tasks; erasing a user deletes their OAuth connections on the host (Beacon `TaskProviderTokensEraser` extended
  to `kind = oauth`) and sets `created_by_id` / `started_by_id` of plans to null.
- **Retention**: finished plans are kept `plan.keep_days` (default 90) for the summary, then pruned by the existing
  history pruning command.

## 17. SSRF and outbound rules

- API base `https://api.clickup.com/api/v2/` and authorize base `https://app.clickup.com/api` are **constants**, never
  configuration or input. Every ClickUp id in a path is checked with the existing `/^[A-Za-z0-9_-]{1,64}$/D`.
- Token exchange: `POST` to the constant URL, no redirects, TLS verify on, 10 s timeout, 16 KiB response cap, JSON with
  exactly one string `access_token` (≤ 512 bytes, printable ASCII) else `oauth_exchange_failed`.
- The token is sent only to `api.clickup.com` (existing rule); never to attachment hosts or app.clickup.com links.
- Attachments: existing `RemoteFileFetcher` (allow-listed attachment hosts, private-address refusal after DNS, optional
  OutboundUrlGuard pinning, redirects re-checked, size and type caps).
- The host's `redirectUri` is validated by the helper (https, no credentials, no fragment) and must equal the registered
  one; the `returnTo` after connecting must be a bundle route of the same tenant (§5.2).
- Phase 2 webhook receiver: no outbound call on receipt; signature verified in constant time before parsing; body cap
  1 MiB; events only enqueue an incremental slice.

## 18. Worker mode (FrankenPHP) and timeouts

- New services are stateless or `ResetInterface` (house rule: bundles must work in worker mode **with and without** kernel
  reset). `ProviderRateBudget` keeps no counter in memory (storage-backed); the OAuth client keeps no state; the plan
  services hold no entity between calls; every slice clears the private entity manager after each page (existing).
- No `ProviderCredential` survives a request or a message: resolved per slice, dropped after.
- REQ-RUNTIME-001 timeout hierarchy: the inline runner spends ≤ `inline_slice_seconds` (default 20 s) per poll, below
  `max_execution_time`; the Messenger slice ≤ 120 s, below the transport's redelivery / worker time limit; the token
  exchange ≤ 10 s.
- Worker-memory test (existing harness): 200 plan slices in one kernel, memory growth bounded (BypassFinals artefact
  excluded).

## 19. Concurrency (REQ-RUNTIME-003)

| Race | Guard |
| --- | --- |
| Two plans for the same tenant + workspace | Plan lock (Symfony Lock, key `taskboard:plan:<tenant>:clickup:<workspace>`, TTL with heartbeat) taken at `start()`; the second start gets *An import of this workspace is already running* |
| Two workers on the same plan (redelivery) | Plan row `running` + `heartbeat_at` compare-and-set (`UPDATE … WHERE id = ? AND (heartbeat_at < ? OR heartbeat_at IS NULL)`); the loser exits |
| Board created twice for one item | Board creation and item update in one transaction under the plan lock; unique (`plan_id`, `source_type`, `source_id`) |
| A plan item and a manual *Refresh* of the same board | Existing board run lock (`busy`) — the plan item retries later |
| Two managers editing the draft | `version` optimistic lock on every step |
| People mapping saved while the plan runs | Existing reassigner locks per board + `PeopleMappingService::resume()` at the end of each board's run |
| Token replaced while running | Next slice resolves the new token through `credentialFor()`; nothing cached |
| Deadlocks / lock waits | DoctrineDeadlockRetry bridge where present; otherwise the page transaction is retried once, then the slice ends `paused` |

Tests: two-process integration tests (under `tests/Integration`, like the existing sync lock tests) for each row.

## 20. Architecture (REQ-ARCH-001, private entity manager)

- **Ports (framework-free, rule 1)**: `ProviderCredentialSourceInterface` (existing; `ConnectionChoice` gains optional
  fields), new `OAuthConnectionStartInterface` (optional), `ImportPlanRunnerInterface`, `ProviderRateBudgetStorageInterface`
  (optional; default Symfony cache). Each with its `*Gate` in `src/Port` and a value-object-only signature
  (`TaskBoardSnapshot`, `TenantRef`, ids) — no Doctrine entity crosses a port.
- **XML mapping** (rule 2): the two new tables in `Resources/config/doctrine-xml` and attributes, same tables.
- **User reference** (rule 2): `created_by_id` / `started_by_id` follow `database.user_reference` (entity or id string).
- **Private entity manager** (rule 6, the only mode since 3.0): every new write goes through the bundle's private EM; no
  flush / clear of the host's EM; the host's `credentialFor()` must not rely on entities the bundle loaded (Beacon's
  `UPDATE … lastUsedAt` DQL is already outside the unit of work).
- **Events after host commit** (rule 7): `SYNC_IMPORT_PLAN_STARTED` / `_FINISHED` / `_FAILED` follow
  `events.after_host_commit`.
- **Deptrac** (REQ-ARCH-002 pilot): `Sync\OAuth` and `Sync\Plan` layers may depend on `Sync\Provider`, `Sync\Http`,
  `Port`, `ValueObject`; not on `Controller` or `Form`.

## 21. Threat model

| # | Threat | Mitigation | Test |
| --- | --- | --- | --- |
| T1 | Token in a DB dump | Host-encrypted (Beacon Halite); bundle stores none | Dump grep of the bundle tables for the fixture token |
| T2 | Token in logs, events, Messenger, cache, exceptions | `ProviderCredential` / `OAuthGrant` redacted + non-serializable; codes not bodies; rate key is an HMAC of the ref | Serialization throws; log / event / message scans in tests |
| T3 | OAuth CSRF / login-CSRF (attacker's ClickUp account bound to a victim's tenant) | Single-use `state` (43 chars, TTL 600 s) bound to session + actor + tenant + purpose; callback re-checks the actor may manage connections; no PKCE available, so the code is useless without the server-held secret | Replay, other session, other tenant, expired state |
| T4 | Open redirect through `returnTo` | Only bundle routes of the same tenant | Absolute / protocol-relative / other-tenant URLs refused |
| T5 | SSRF | Constant hosts, id regex, attachment allow-list + private-address refusal | Existing fetcher suite + id fuzz |
| T6 | Cross-tenant import (connection or board of another tenant) | Tenant checked at choose, start and every slice; `credentialFor()` tenant-scoped | Cross-tenant ids in every step |
| T7 | Privilege escalation through people mapping | "A mapping never grants access" (existing) | Existing suite |
| T8 | Malicious remote content (XSS, Markdown, bidi) | `RemoteContentSanitizer`, escaped rendering, URL scheme checks (existing) | Existing suite |
| T9 | Writing to ClickUp with a read-write token | Adapter refuses non-GET methods (phase 1) | Unit test per method |
| T10 | Rate-limit exhaustion affecting other boards / the user's own ClickUp use | Shared budget with margin; sequential items; preview ETA | Budget tests with a fake clock |
| T11 | Webhook forgery (phase 2) | HMAC verification, constant time, per-webhook secret held by the host | Phase 2 |

## 22. Testing strategy

- **Synthetic fixtures only.** Recorded-shape JSON under `tests/Fixtures/ClickUp/Api/` (workspaces, spaces, folders,
  lists, tasks with every field type, comments with threads and files, checklists, time entries, 429 / 401 / 5xx
  answers) written by hand or recorded from the owner's **test workspace filled with synthetic data**, then scrubbed by a
  script that fails on any e-mail not under `example.test`, any `pk_` / long token-like string and any real name list. No
  customer data, no real token in the repository (house rule "never upload secrets").
- **`FakeClickUp`**: a `MockHttpClient` callback over an in-memory workspace (extends `InMemoryTaskProvider` scenarios),
  with scripted rate-limit headers and failures; it asserts the header form per scheme (`Bearer` for oauth, raw for
  personal) and that only GET is used.
- **Unit**: `OAuthState` (regex with `/D`, trailing newline, length), `ClickUpOAuthClient` (URL building, redirect-URI
  validation, exchange response validation, error codes, no body in exceptions), field mapping (native vs synced per
  type), plan state machine, rate budget (fake clock), cursor encode / decode.
- **Integration** (SQLite + PostgreSQL + MySQL in CI): plan draft → preview → start → slices → summary; re-import
  idempotency (second run writes nothing; counts "unchanged"); crash between board creation and item update; adoption of
  a CSV-imported board; private EM isolation (host EM untouched).
- **Concurrency** (`tests/Integration`, REQ-RUNTIME-003): every row of §19.
- **Worker** (FrankenPHP harness): memory and state leak across 200 slices with and without kernel reset.
- **Demo + Playwright + axe** (`demo/symfony8`): the wizard end to end with the in-memory provider and a demo OAuth stub
  host controller (no network), keyboard-only path, no-JS path for the decisive steps.
- **Live check** (manual, build task 1 and before each release of this feature): one run against the owner's synthetic
  test workspace with a token the owner supplies at runtime (never stored in the repo or CI) to confirm the TO CONFIRM
  items of §4.

## 23. Phasing, versions and Beacon integration

| Slice | Version (proposed) | Content |
| --- | --- | --- |
| **CU-1** OAuth scheme | 3.1.0 | `scheme = oauth` (Bearer) in the adapter, `ClickUpOAuthClient`, `OAuthState`, `OAuthConnectionStartInterface`, `ConnectionChoice` extras, GET-only guard, host recipe in sync.md, demo stub. Immediate SEC-004 audit (auth / tokens) |
| **CU-2** Import plans + wizard | 3.2.0 | Tables, `Sync\Plan`, wizard steps 8.1 – 8.9, inline + optional Messenger plan runner, shared rate budget, `createOnly`, events, summary, Playwright + axe |
| **CU-3** Native fields | 3.3.0 | Native mapping for text / number / drop-down, provenance marker, conflict rule |
| **Phase 2a** Scheduled incremental | 3.4.0 | `mode = follow` per item: scheduled `runLinked()` incremental (existing engine), "future lists of a chosen folder / space" option |
| **Phase 2b** Webhooks | later | Host-owned receiver route recipe + bundle verifier / dispatcher, `taskCreated` / `taskUpdated` / `taskDeleted` / `taskMoved` / `taskComment*` → enqueue an incremental slice of the board; nightly full scan stays as the safety net |

Each slice closes every REQ gap of the bundle before its tag (owner rule "touched bundle = 100 % REQs"); one batched audit
before the tags, immediate audit for CU-1 (auth).

**Beacon side (for the Beacon spec that follows)**

- Register the ClickUp OAuth app (workspace owner / admin of a Nowo workspace): production redirect
  `https://beacon.nowo.tech/…/tasks/clickup/oauth/callback` and a dev app for `https://localhost:9447/…` (exact path chosen
  in the Beacon spec). Client id / secret as InstanceSettings runtime settings (encrypted); never in the repo.
- Extend `TaskProviderToken` (`kind`, `remote_user_id`, `remote_user_name`, `workspace_ids`, `app_key`) + migration;
  extend `ProjectProviderTokens` (`connectionsFor()` fills `kind` / `workspaceIds`; `credentialFor()` sets
  `options['scheme']`); implement `OAuthConnectionStartInterface`; add start / callback actions to
  `TaskProviderTokenController` (state in session, recipe §5.2); extend `TaskProviderTokensEraser` and the personal data
  export.
- Grant the tenant action `import_plan` to project managers / organisation admins in Beacon's access listener; wire the
  plan runner to Beacon's Messenger transport (or the bundle's runner); NotifyKit message on `SYNC_IMPORT_PLAN_FINISHED`.
- Manual section (7 locales), e2e (Playwright) with the demo stub, and the processing record entry.
- Today's personal-token list sync keeps working unchanged; existing connections can be switched to OAuth by reconnecting
  (same ref, token replaced) with no board change.

## 24. Questions for the owner (Q-CU)

| # | Question | Recommended answer |
| --- | --- | --- |
| Q-CU-1 | Who owns the OAuth connection: A host-owned (bundle helper, no bundle route or token storage), B bundle-owned (CrmKit H3 pattern), C host-owned with an opt-in bundle store? | **A.** Keeps the 1.23 "bundle never stores a token" rule and MailKit Q-254-15; Beacon already has encrypted tokens, erasure and the form. C can be added later without breaking anyone |
| Q-CU-2 | One ClickUp OAuth app per instance, or per tenant (bring your own app)? | **Per instance** (InstanceSettings runtime setting, encrypted). Per-tenant apps only if a customer asks |
| Q-CU-3 | `OAuthState` helper: copy TimeTrack's into TaskBoard, or extract both into a tiny shared package? | **Copy into TaskBoard now** (≈ 40 lines, no new dependency); record a dedup candidate for the next dedup survey |
| Q-CU-4 | ClickUp has no PKCE and no scopes. Accept OAuth with a confidential client + single-use state only, and enforce read-only in the adapter (GET only)? | **Yes**, documented residual risk: a stolen token could write in ClickUp; mitigated by host encryption and by the GET-only adapter |
| Q-CU-5 | Default board layout in the wizard? | **One board per list**; *per folder* and *one board* as options |
| Q-CU-6 | People directory scope for plans: per board (today's default), or per tenant + workspace? | **Per tenant + workspace** for plans (`tenant:<key>:clickup:<workspace>`), so people are mapped once for all boards of the import |
| Q-CU-7 | Native custom fields: offer them in CU-3 for text / number / drop-down only, with a local edit winning on re-import? | **Yes**; other types stay synced read-only until native types grow (ROADMAP) |
| Q-CU-8 | Re-import modes: *Update changed tasks* (default) and *Only add new tasks*; no "overwrite local edits"? | **Yes** |
| Q-CU-9 | Background: ship an opt-in Messenger plan runner in the bundle, or keep the host-implemented runner only (as today for sync)? | **Ship it opt-in** (`sync.plan.messenger.enabled: false`); Beacon may use it or keep its handler |
| Q-CU-10 | Who may start an import plan by default? | **Deny by default**; `security.manage_roles` holders or the host's grant (Beacon: project managers and organisation admins) |
| Q-CU-11 | Map ClickUp recurrence to TaskBoard recurring tasks? | **No** in phase 1: read-only text as today (the v2 answer rarely carries it) |
| Q-CU-12 | Allow the same ClickUp workspace to be imported into two tenants? | **Yes** (tenants are isolated; ids are unique per tenant only); warn in the preview when the workspace id is already used by another plan of the same tenant |
| Q-CU-13 | Keep finished plans (summary, report JSON with counts and ids) for how long? | **90 days**, pruned with the sync history |
| Q-CU-14 | Phase 2: scheduled incremental first (3.4.0), webhooks after it once a live workspace confirms the signature and OAuth-created webhooks? | **Yes**, scheduled first; webhooks behind a separate option with nightly full scan as safety net |
| Q-CU-15 | Time entries in the guided import: on when the host has a sink (Beacon: yes), member tokens import only the connecting user's own time with a visible warning? | **Yes** (existing behaviour, made visible in the preview) |
| Q-CU-16 | ClickUp may add token expiry / refresh later ("subject to change"). Model optional `expiresAt` / refresh in `OAuthGrant` now (unused), so it lands as a minor? | **Yes**, fields present and null; no refresher code until ClickUp changes |
