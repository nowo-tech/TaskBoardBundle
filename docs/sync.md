# Provider connections and sync (since 1.23)

A board can follow a list of another task tool and keep it up to date: the first run imports every task, later runs
create the new ones, update the changed ones, archive the ones the provider deleted and restore the ones that come
back. ClickUp (API v2) is the first provider; the contract is ready for others.

The feature is **opt-in** (`sync.enabled: false` by default): without it there are no routes, no services, no Doctrine
mapping of the sync tables and no header change.

## Table of contents

- [What the host provides](#what-the-host-provides)
- [Configuration](#configuration)
- [Connecting ClickUp with OAuth (since 3.1.0)](#connecting-clickup-with-oauth-since-310)
  - [What the bundle ships](#what-the-bundle-ships)
  - [Host recipe: start and callback routes](#host-recipe-start-and-callback-routes)
  - [Storing the token and the app credentials](#storing-the-token-and-the-app-credentials)
  - [Security notes and residual risk](#security-notes-and-residual-risk)
- [Guided import from ClickUp: import plans (since 3.2.0)](#guided-import-from-clickup-import-plans-since-320)
  - [Turning it on](#turning-it-on)
  - [The steps](#the-steps)
  - [How a plan runs](#how-a-plan-runs)
  - [Shared request budget per token](#shared-request-budget-per-token)
  - [Host checklist](#host-checklist)
- [How a run works](#how-a-run-works)
- [Field rules and conflicts](#field-rules-and-conflicts)
- [Deletions and the mass-archive guard](#deletions-and-the-mass-archive-guard)
- [Files and images](#files-and-images)
- [Running it in the background](#running-it-in-the-background)
- [Console command](#console-command)
- [UI](#ui)
- [People directory (since 1.25)](#people-directory-since-125)
- [Creator, assignees, watchers, checklists and comments (since 1.25)](#creator-assignees-watchers-checklists-and-comments-since-125)
- [Statuses and sources (since 1.25)](#statuses-and-sources-since-125)
  - [Columns no status uses (since 1.28.0)](#columns-no-status-uses-since-1280)
- [Tracked time (since 1.25)](#tracked-time-since-125)
  - [Hours of people with no user (since 1.28.0)](#hours-of-people-with-no-user-since-1280)
- [Pre-scans and re-association (since 1.25)](#pre-scans-and-re-association-since-125)
- [People page and step (since 1.27.0)](#people-page-and-step-since-1270)
  - [Former people and attribution only (since 1.28.0)](#former-people-and-attribution-only-since-1280)
- [Everything a ClickUp task has (since 1.27.0)](#everything-a-clickup-task-has-since-1270)
  - [Typed custom fields](#typed-custom-fields)
  - [Comments in full](#comments-in-full)
  - [Dates, points, links, ids, location, status history, archived tasks, recurrence](#dates-points-links-ids-location-status-history-archived-tasks-recurrence)
  - [Completeness report](#completeness-report)
  - [Time entries one by one](#time-entries-one-by-one)
- [Events](#events)
- [Testing with the in-memory provider](#testing-with-the-in-memory-provider)
- [Adding a provider](#adding-a-provider)

## What the host provides

The bundle **never stores a token**. The host keeps the credentials and gives one for each run.

| Hook (service id in `sync.*`) | Required | Default |
| --- | --- | --- |
| `credential_resolver`: `Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface` | **yes** | — (the container build fails without it) |
| the same service also implementing `Sync\Hook\OAuthConnectionStartInterface` (since 3.1.0) | no | no *Connect with ClickUp* link (tokens are pasted on the host's form) |
| `attachment_storage`: `SyncedFileStorageInterface` | no | files are counted as refused (`storage_disabled`) |
| `inline_image_rewriter`: `SyncedImageRewriterInterface` | no | `MapInlineImageRewriter` (stored copy, else the text "[image not copied]") |
| `limit_checker`: `SyncQuotaPolicyInterface` | no | no host limit |
| `runner`: `SyncRunnerInterface` | no | `InlineSyncRunner` (one slice per request; the progress page continues it) |
| `actor_resolver`: `SyncActorProviderInterface` | no | the user of the request, else the user who linked the board; since 1.24 a disabled user (`isEnabled()` false) or one `actor_checker` refuses is never the actor, and a named user that is refused, no longer found or fails to load is not replaced by the linker |
| `actor_checker`: Symfony `UserCheckerInterface` (since 1.24) | no | none: only `isEnabled()` is checked |
| `people.resolver`: `SyncPersonDirectoryInterface` (since 1.25) | no | `DefaultSyncPersonResolver`: one directory per board, the board's assignable users as candidates, 1.23 e-mail lookup |
| `time_entries.sink`: `ImportedTimeEntryReceiverInterface` (since 1.25) | no | none: the `time_entries` phase never runs |
| `BOARD_ACCESS_CHECK` listener for `import_tasks` (since 1.25) | no | granted: who may edit the board (and, for the connection, manage it) may import |

```php
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

final class ProjectConnections implements ProviderCredentialSourceInterface
{
    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        // The stored connections this user may use on this board: an opaque ref, a label, the last 4 characters.
        return [new ConnectionChoice('42', 'Team token', 'a1b2')];
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        // Decrypt the token for one run; null when the connection is gone or no longer allowed for this board.
        return new ProviderCredential($this->decryptedToken($connectionRef), $connectionRef);
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return '/settings/connections/new?provider=' . $provider; // where a token is pasted
    }
}
```

`ProviderCredential` is built for one run. It is never stored, logged or dispatched: `print_r()` / `var_dump()` show
`[redacted]`, and serializing it throws (so it cannot travel in a Messenger message). Only provider adapters read it
(`reveal()`).

## Configuration

```yaml
nowo_task_board:
    sync:
        enabled: true
        credential_resolver: App\Tasks\ProjectConnections
        attachment_storage: App\Tasks\StorageKitAttachments   # optional
        limit_checker: App\Tasks\PlanLimits                   # optional
        runner: App\Tasks\MessengerSyncRunner                 # optional
```

Every key and its default is in [CONFIGURATION.md](CONFIGURATION.md#provider-sync-since-123). The schema change is in
[UPGRADING.md](UPGRADING.md).

## Connecting ClickUp with OAuth (since 3.1.0)

A host can offer **Connect with ClickUp** (OAuth 2, authorization code) next to *Add a token*. The connection is the
host's: its routes, its session, its encrypted token store. The bundle has **no callback route and stores no token and
no client secret** (the 1.23 rule holds); it ships a stateless helper and a credential scheme. Design and decisions:
[design/CLICKUP_IMPORT.md](design/CLICKUP_IMPORT.md) §5 – §7 (Q-CU-1 … Q-CU-4, Q-CU-16).

### What the bundle ships

| Piece | What it does |
| --- | --- |
| `ProviderCredential` option `scheme` (`Sync\Provider\CredentialScheme`) | `personal` (default when missing): the ClickUp adapter sends `Authorization: <token>` as since 1.23. `oauth`: it sends `Authorization: Bearer <token>`. Any other value is refused before a request (`unsupported_credential`). Personal tokens keep working unchanged |
| `Sync\OAuth\ClickUpOAuthClient` (service, autowirable, only with `sync.providers.clickup.enabled`) | `authorizationUrl($clientId, $redirectUri, OAuthState $state)`: ClickUp's consent URL (constant base `https://app.clickup.com/api`). `exchange($clientId, $clientSecret, $code)`: `POST https://api.clickup.com/api/v2/oauth/token` with the parameters in the JSON body, no redirects, 10 s, 16 KiB answer cap, returns an `OAuthGrant`. `describe(ProviderCredential)`: `GET /user` + `GET /team`, returns an `OAuthIdentity` (user id, user name, authorised workspaces; never the e-mail) |
| `Sync\OAuth\OAuthState` | the single-use `state`: `create()` (32 random bytes, 43 base64url characters), `fromString()`, `isValid()` (`/D` regex), `matches()` (constant time) |
| `Sync\OAuth\OAuthGrant` | the access token for the host to encrypt; redacted in dumps, serialization throws; `expiresAt` / `refreshToken()` exist and are always null (ClickUp tokens do not expire today, Q-CU-16); `toCredential($ref)` builds the `oauth` credential |
| `Sync\OAuth\OAuthException` + `OAuthErrorCode` | `oauth_denied`, `oauth_state_invalid` (for the host's callback), `oauth_exchange_failed`, `oauth_token_rejected`; the HTTP status, never the response body; translation keys `task_board.sync.oauth.error.<code>` |
| `Sync\OAuth\OAuthReturnPath::isSafe()` | the check of the `returnTo` the host's start route receives (a same-origin path only) |
| `Sync\Hook\OAuthConnectionStartInterface` (optional) | `connectUrl(TaskBoardSnapshot $board, string $provider, string $returnTo): ?string` on the `credential_resolver` service: the connection page links it as *Connect with ClickUp* (and *Connect again* after `auth_failed` / `forbidden`). `returnTo` is the board's connection page |
| `ConnectionChoice` `kind` / `workspaceIds` | optional: `CredentialScheme::OAuth` labels the connection "OAuth" in the picker; `workspaceIds` lists the authorised workspaces (used by the guided import, CU-2) |

Every ClickUp call of the adapter is a `GET` (tested): an OAuth token has no scopes, so read-only is the adapter's rule.

### Host recipe: start and callback routes

The authorization below is code, not a comment: copy it with the routes (audit ZBT-L1). Both routes refuse with the
same 404 the bundle's own connection pages answer to someone who may not manage the board's connection.

```php
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Hook\OAuthConnectionStartInterface;
use Nowo\TaskBoardBundle\Sync\OAuth\ClickUpOAuthClient;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthErrorCode;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthException;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthReturnPath;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthState;

// 1. The credential source links the start route (same service as sync.credential_resolver).
final class ProjectConnections implements ProviderCredentialSourceInterface, OAuthConnectionStartInterface
{
    public function connectUrl(TaskBoardSnapshot $board, string $provider, string $returnTo): ?string
    {
        return $provider === 'clickup' ? $this->urls->generate('app_clickup_oauth_start', ['board' => $board->id, 'returnTo' => $returnTo]) : null;
    }
    // connectionsFor() returns OAuth rows as new ConnectionChoice($ref, $label, $lastFour, CredentialScheme::OAuth, $workspaceIds);
    // credentialFor() returns new ProviderCredential($token, $ref, ['scheme' => 'oauth']) for them.
}

final class ClickUpOAuthController extends AbstractController
{
    private const PURPOSE = 'task_board_connection';

    public function __construct(
        private readonly ClickUpOAuthClient $oauth,
        private readonly TaskBoardRepositoryInterface $boards,
        private readonly TaskBoardResourceAccessChecker $access,
    ) {
    }

    // 2. Start route: only a user who may manage this board's connection.
    #[Route('/settings/clickup/oauth/start', name: 'app_clickup_oauth_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        $board    = $this->managedBoard((string) $request->query->get('board'));   // deny path: 404
        $returnTo = (string) $request->query->get('returnTo');
        // Same-origin path AND this board's connection page (the only page the bundle sends here).
        $page = $this->generateUrl('nowo_task_board_board_connection', ['boardId' => $board->getId()]);
        if (!OAuthReturnPath::isSafe($returnTo) || ($returnTo !== $page && !str_starts_with($returnTo, $page . '?'))) {
            throw new BadRequestHttpException();
        }
        $state = OAuthState::create();
        $request->getSession()->set('clickup_oauth', [
            'state' => $state->value, 'actor' => $this->getUser()->getUserIdentifier(), 'tenant' => $board->getTenantKey(),
            'board' => $board->getId(), 'purpose' => self::PURPOSE, 'returnTo' => $returnTo, 'expires' => time() + 600,
        ]);

        // ABSOLUTE_URL uses the request host: set framework.trusted_hosts (or a fixed base URL) in production.
        return $this->redirect($this->oauth->authorizationUrl($this->clientId(), $this->generateUrl('app_clickup_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL), $state));
    }

    // 3. Callback route: the exact URL registered with the ClickUp app.
    #[Route('/settings/clickup/oauth/callback', name: 'app_clickup_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $pending = $session->get('clickup_oauth');
        $session->remove('clickup_oauth');                                  // single use, whatever happens next
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || !OAuthState::isValid($pending['state'])
            || ($pending['purpose'] ?? null) !== self::PURPOSE || ($pending['expires'] ?? 0) < time()
            || !OAuthState::fromString($pending['state'])->matches($request->query->get('state'))
            || ($pending['actor'] ?? null) !== $this->getUser()?->getUserIdentifier()) {
            return $this->failed(OAuthErrorCode::StateInvalid);
        }
        // Authorization again, in code: the user may have lost the right (or the board its tenant) since the start.
        $board = $this->managedBoard((string) $pending['board']);           // deny path: 404, nothing exchanged
        if ($board->getTenantKey() !== $pending['tenant']) {
            throw $this->createNotFoundException();
        }
        $code = $request->query->get('code');
        if ($request->query->has('error') || !is_string($code) || $code === '') {
            return $this->failed(OAuthErrorCode::Denied);
        }
        try {
            $grant    = $this->oauth->exchange($this->clientId(), $this->clientSecret(), $code);
            $identity = $this->oauth->describe($grant->toCredential('pending'));   // ≤ 10 s, no retry; 429 = oauth_rate_limited
        } catch (OAuthException $exception) {
            return $this->failed($exception->errorCode);
        } catch (ProviderException) {
            return $this->failed(OAuthErrorCode::ExchangeFailed);
        }
        // One connection per (tenant, ClickUp user, app): replace the token of an existing row (same ref, boards keep
        // working), else create one. Encrypt $grant->accessToken(); keep $identity->userId, ->userName,
        // ->workspaceIds() and $grant->lastFour().
        $this->connections->storeOAuth($board->getTenantKey(), $identity, $grant);

        return $this->redirect($pending['returnTo']);
    }

    private function managedBoard(string $boardId): TaskBoard
    {
        $user  = $this->getUser();
        $board = $boardId === '' ? null : $this->boards->findById($boardId);
        if (!$user instanceof UserInterface || !$board instanceof TaskBoard || $board->getTenantKey() !== $this->currentTenant()
            || !$this->access->canManageBoardConnection($user, $board)) {
            throw $this->createNotFoundException();   // deny: same answer as the bundle's connection pages
        }

        return $board;
    }

    private function failed(OAuthErrorCode $code): Response
    {
        $this->addFlash('error', 'task_board.sync.oauth.error.' . $code->value);   // NowoTaskBoardBundle domain

        return $this->redirectToRoute('app_settings_connections');
    }
}
```

ClickUp has no documented revoke endpoint: *Disconnect* deletes the stored token and tells the user to remove the app
under ClickUp's *Settings → Apps*.

### Storing the token and the app credentials

- **Token**: encrypted at rest with the host's mechanism (for example DoctrineEncrypt / Halite), shown as its last four
  characters only, erased with the user or tenant like a pasted token. It reaches the bundle only through
  `credentialFor()`, for one run slice.
- **App** (Q-CU-2): one ClickUp OAuth app per instance, registered by a workspace owner or admin with the exact callback
  URL. Keep its client id and client secret as instance runtime settings (InstanceSettings: env by default, DB
  switchable, the secret **encrypted** with InstanceSettings' sealer) and pass them to the helper on each call. Never
  put a real client id or secret in the repository, a fixture or CI.
- The code exchange goes through the bundle's own HTTP client. If you set `sync.http.client`, point it at an
  **untraced** client: the profiler-traced `http_client` records request bodies, i.e. the client secret and the code
  (audit ZBT-I2).
- The bundle has no configuration key for OAuth: everything above lives in the host. `http://localhost` /
  `127.0.0.1` redirect URIs are accepted only when `kernel.debug` is on (a separate dev app).

### Security notes and residual risk

- **No PKCE, no scopes** (Q-CU-4): ClickUp supports neither. The protection is the confidential client (the secret
  never leaves the server; the code is useless without it) plus the single-use `state` bound to session, actor, tenant
  and purpose (TTL 600 s). The token can do whatever its user can do in the authorised workspaces, **including
  writes**; TaskBoard only ever sends `GET`. Residual risk: a stolen token could write in ClickUp. Mitigations: host
  encryption at rest, no token in the bundle's tables, logs, events, messages, caches or exceptions, and the GET-only
  adapter.
- **Open redirect**: the bundle passes its own connection page as `returnTo`; the host re-checks it with
  `OAuthReturnPath::isSafe()` (same-origin path) and, as in the recipe, compares it with that board's connection
  route before keeping it server side. The helper stays same-origin only because only the host knows its route
  prefix and tenant scheme; the recipe adds the bundle-route check (audit ZBT-I4). The links the bundle shows (`connectUrl()`,
  `credentialFormUrl()`) must be `https://` or same-origin paths, else they are not shown.
- **Token exchange parameters**: ClickUp's reference documents `client_id`, `client_secret` and `code` as the
  request body (`application/json` or form-encoded, checked 2026-11-25); the helper sends JSON, never the query
  string, so the secret stays out of access logs. Check the first exchange against your instance app before
  enabling the button (audit ZBT-I6).
- **Callback URL**: without PKCE the code is protected by ClickUp matching the registered redirect URL. Build it from
  a trusted host (`framework.trusted_hosts`, or a configured base URL) and keep no open redirect on that origin.
- **Interactive budget**: `exchange()` (10 s) and `describe()` (two GETs of 5 s, no retry, no inline wait; a 429 is
  `oauth_rate_limited` at once) never use the sync client's retry policy, so a slow or throttled ClickUp cannot hold
  the callback request or a FrankenPHP worker for minutes (audit ZBT-L2).
- **Exceptions** carry a code and an HTTP status, never the body (ClickUp could echo the code or the secret) and never
  a chained HTTP client exception.
- **Worker mode**: the helper and the value objects keep no state between requests (FrankenPHP worker with and
  without kernel reset); no write happens in the bundle, so CU-1 adds no lock or race (REQ-RUNTIME-003).
- Shared per-token rate budget (100 requests / minute on most plans) comes with the guided import (CU-2).

## Guided import from ClickUp: import plans (since 3.2.0)

Phase CU-2 of [design/CLICKUP_IMPORT.md](design/CLICKUP_IMPORT.md). An **import plan** brings several ClickUp lists of
one workspace into boards of one tenant in one resumable background job. Each plan item is an ordinary board
connection once linked, so everything above (runs, history, People page, completeness report, unlink) applies to it.

### Turning it on

```yaml
nowo_task_board:
    sync:
        enabled: true
        credential_resolver: App\Tasks\ProviderTokens   # also implements TenantCredentialSourceInterface
        plans:
            enabled: true                 # routes import_plans, import_plan, … and "Import from ClickUp" on the index
            # runner: app.my_plan_runner  # a Sync\Plan\ImportPlanRunnerInterface; default inline (or Messenger below)
            messenger:
                enabled: false            # true = RunImportPlanSlice messages (route them to an async transport)
                slice_seconds: 120
            inline_slice_seconds: 20
            keep_days: 90                 # finished plans and abandoned drafts are pruned after this
            preview_max_pages: 3
            max_items: 200
        rate_limit:
            enabled: true
            storage: null                 # a PSR-6 pool shared by web and worker processes; null = cache.app
            default_limit: 100
            margin: 0.1
```

- **Tenant connections.** The wizard starts from a tenant before any of its boards exists, so the
  `credential_resolver` service also implements `Sync\Hook\TenantCredentialSourceInterface`:
  `tenantConnectionsFor(TenantRef, user, provider)`, `tenantCredentialFor(TenantRef, ref)`,
  `tenantCredentialFormUrl()` and `tenantConnectUrl(TenantRef, provider, returnTo)` (the OAuth start; `returnTo` is
  the plan's Connect step, a same-origin path — check it with `OAuthReturnPath::isSafe()`). A reference given here must
  work in `credentialFor()` for every board of that tenant: the runner asks it per board. Without the interface the
  plan pages answer 404.
- **Who may import** (Q-CU-10): the tenant action `import_plan` — event `TenantAccessCheckEvent` on
  `TaskBoardEvents::TENANT_ACCESS_CHECK`. Denied by default except to `security.manage_roles` holders; a listener
  calls `grant()` (Beacon: project managers and organisation admins) or `deny()`. Every plan route answers 404 when it
  is denied, and for a plan of another tenant. Creating boards also needs the board-create right and the host's
  `TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE`; filling an existing board needs `canManageBoardConnection()` and
  `canImportTasks()` on it.

### The steps

| Step | What the user does | Notes |
| --- | --- | --- |
| Connect | Picks a connection of the tenant (*Connect to ClickUp* / *Paste a token* link the host's pages) | Says what is read and copied, that nothing is written back, and where to revoke the app |
| Workspace | One workspace per plan | Only the workspaces the connection was authorised for (`ConnectionChoice::$workspaceIds`) |
| Tree | Ticks spaces, folders or lists; picks the layout and options | A ticked space or folder means "its lists now". A level opens by a POST (no provider call on a GET). At most `max_items` ticks; each must lie in the plan's workspace (ClickUp: the workspace's spaces once, then one request per ticked list or folder, from the token's budget) |
| Boards | Per item: *New board* (name) or an existing board of the tenant | A board linked to another source is not offered; a board is used once |
| Statuses | One table per status set: each status → a column, *Create a column*, *Do not import* | Same-named columns preselected (case, accents, spaces ignored) |
| Fields | Per field: *Synced, read-only*, *Do not import* or (text, number, drop-down) a *native field* — create one or pick one of the type | Since 3.3.0 (CU-3), see [Native fields](#native-fields-from-the-import-since-330). Other types say *synced only* |
| People | Counts of the tenant's directory; *Stop after the first board is linked* | One directory per tenant and workspace (scope `tenant:<hash>`) |
| Preview | Counts from ClickUp, requests and minutes, warnings, the host's quota, the re-import mode | *Start import* behind a confirm dialog |
| Run / summary | Progress (polled every 5 s), Cancel, Resume; then counts, failed lists, *Re-import now*, *Retry the failed lists*, *Download report (JSON)*, Delete | The page can be left: the plan goes on |

Layouts (Q-CU-5): **one board per list** (default; a ticked folder or space brings its lists), **one board per
folder** (each folder one board, its lists as `list:<name>` tags with `sync.list_as_tag`; a list outside a ticked
folder stays a board), **one board for everything** (exactly one ticked space, folder or list: a board follows one
source). Options: comments, attachments, checklists, tracked time (only when `sync.time_entries.sink` is set; a member
token imports only its own time and the preview says so, Q-CU-15), and deleting the empty unused columns after the
first import. Recurrence stays read-only text (Q-CU-11).

Re-import modes (Q-CU-8): **Update changed tasks** (the field-hash rule: a local edit wins) and **Only add new
tasks** (`SyncOptions::$createOnly`: imported tasks untouched, deletions not archived). There is no overwrite mode.

### Native fields from the import (since 3.3.0)

Phase CU-3 (owner Q-CU-7). On the Fields step a ClickUp field of type `text` / `short_text`, `number` or `drop_down`
can land on a **native** field: editable on every task of the board, in the list columns and filters, the Task API and
the board's *Custom fields* page. The choice is *Create “name”* (a board field created on first use) or an existing
native field of the same type: the tenant's fields, and the board's own when every item of the plan fills the same
existing board. Every other ClickUp type stays a synced, read-only field (the step says *synced only*; the preview
lists them).

- **Found again by id.** A field the import creates carries the marker `clickup:<field id>`
  (`TaskCustomField::getSourceRef()`, unique per board). A re-import finds it by the marker, whatever it is called
  now. Drop-down options are matched by ClickUp's option id (`getSourceOptions()`): renamed options (there or here)
  stay the same option, new ClickUp options are added, an option deleted here is **not** recreated.
- **No overwrite mode.** The provider's value is written only while nobody changed the task's value here since the last
  import (a per-field hash on the sync item, key `native:<field id>`). A value changed here — or one set before the
  provider had any — is kept, and the provider's value is noted once in a comment, as the other fields' conflicts. An
  empty provider value never clears a value. A value written here and in ClickUp to the same thing is not a conflict.
- **Not written twice.** A field landed on a native field is left out of the synced fields (`task_board_sync_field_*`).
- **Rights.** Creating a field, or landing values on an existing one, needs the run actor's right of the Custom fields
  page (the restructure policy: a board's field `mayRestructureBoard()`, by default the board's manage right; a tenant
  field `mayManageFolders()`). Without it the run warns `native_field_refused`, the definition is not touched and those
  values stay synced, read-only fields. The Fields step offers only the fields its user may change, and never one
  native field to two ClickUp fields. A chosen field that was deleted or retyped warns `native_field_unavailable` (values
  synced too).
- **Deleted here stays deleted.** Deleting an imported field (the Custom fields page, a host through the manager, or a
  board configuration that drops it) leaves a tombstone: the next imports warn `native_field_deleted` and import neither
  the field nor its values — not even as a synced, read-only field (audit ZCFv-L1). *Import it again* on the page clears
  it and makes the next sync read every task again (the connection's watermark is cleared and every task's remote date
  reset; audit ZCFv2-L1): that sync creates the field anew and lands the values of every task on it, also of the tasks
  that did not change in ClickUp. While a sync of the board runs, *Import it again* is refused ("A sync of this board is
  running; try again when it ends", `sync_running`; audit ZCFv3-L1).
- **A definition changed under the run.** When the field's definition changed after the run's transaction started
  reading (a host transaction around the import), the option merge is refused: the run warns `native_field_changed`, defers the tasks that carry the field
  (`fields.native_deferred`) and keeps the previous watermark, so the next run, incremental or not, imports those
  values (audit ZCFv2-L1).
- **A synced copy left behind (audit ZCFv-I2).** A field that fell back to a synced field for a while (refused or
  unavailable) keeps that synced copy, with its last values, once it lands on a native field; the copy stops updating.
  `SyncFieldValueEraser::eraseField()` removes it per task.
- **Counts** in the run report: `fields.native_fields`, `fields.native_values`, `fields.native_conflicts`,
  `fields.native_misfit` (a value that did not fit the provider's own type), `fields.native_invalid`.

### How a plan runs

1. **Start** checks the actor, the host's board quota and the connection, takes the workspace lock (one active plan
   per tenant, provider and workspace; a second start is refused) and creates the new boards — each in one
   transaction with its item, so a crash never creates a second board — then dispatches the runner.
2. **Each slice** claims the plan (one worker; a heartbeat older than ten minutes is taken over) and gets a token
   every later write of the slice is conditional on (a slice that was taken over writes nothing more). It always runs
   as the user who **started** the plan — never as whoever polls or resumes it — and re-checks that user at each board
   (`SyncActorProviderInterface`, `import_plan` in the tenant, connect and import rights on the board); a refused
   starter fails the plan as `actor`. Then for each item in order links the
   board to its source with the plan's status map, options, excluded fields and directory, and runs its connection
   through `runLinked()` until the slice's time is used.
3. **Endings of a slice**: time used or the board's run paused (due now), the token's budget or ClickUp's 429
   (`rate_limited`, due after the wait plus 0–20 % jitter), an outage (`paused`, due after 1, 2, 4, 8, 15 minutes,
   five times, then the list fails), the People wait (`paused` until *Resume*), a connection that no longer works
   (`failed`, code `credential`: reconnect under the same reference and re-import — it continues where it stopped).
   A failed list never stops the others; the summary offers to retry it.
4. **End**: `SYNC_IMPORT_PLAN_FINISHED` (succeeded or cancelled) or `SYNC_IMPORT_PLAN_FAILED`, with ids and counts only,
   for the host to notify the actor. Plans older than `keep_days` are pruned then.

With the inline runner the progress endpoint (a GET, like the board connection's progress since 1.23) advances a due
plan by one slice per poll, as the starter. The Statuses step's GET stores each source's statuses on its draft item
(a cache of the draft, nothing else). With
`sync.plans.messenger.enabled` the handler queues the next message with a `DelayStamp`; route `RunImportPlanSlice`
to an async transport (a sync transport ignores the delay). A host runner implements `ImportPlanRunnerInterface`
and calls `ImportPlanRunner::runSlice($planId, $seconds)` (the slice always acts as the starter); it returns null when
nothing ran. *Cancel* ends a waiting plan at once; a running one ends `cancelled` before its next board or instead of
pausing.

### Shared request budget per token

ClickUp limits requests **per token** (100 a minute on most plans). Several plan items, a list sync of another board
and the People scan may use one token at once, so `ProviderRateBudget` counts the requests of each token in one-minute
windows in `sync.rate_limit.storage` and stops at the last `X-RateLimit-Limit` seen less `margin`. It is on for every
ClickUp sync, not only plans, and it is per connection **reference**: keep references globally unique. A full window waits
inline when it ends within `sync.http.max_inline_wait`, otherwise the slice ends `rate_limited`. The key is an HMAC of
`clickup:<connection ref>` with `kernel.secret`; the token never reaches the pool. Use a pool every process shares
(Redis): `cache.app` is shared by the processes of one server only, and an APCu or array pool counts per process. The count is read-modify-write, so two processes
may both take a window's last request; the margin and ClickUp's own 429 handling cover it. A failing pool lets the
request go.

### Host checklist

- Implement `TenantCredentialSourceInterface` on the credential source; keep tokens encrypted (see OAuth above).
- Grant `import_plan` in a `TENANT_ACCESS_CHECK` listener to the roles that may import.
- Turn on `sync.plans.enabled`; pick the runner (inline, the bundle's Messenger runner, or yours).
- Apply the schema change of UPGRADING "To 3.2.0".
- Notify the actor on `SYNC_IMPORT_PLAN_FINISHED` / `_FAILED` if you want to.
- Erasure: `TaskSyncPeopleReassigner::detachUser()` clears the user's id from plans; your erasure keeps calling it.

## How a run works

`Nowo\TaskBoardBundle\Sync\TaskBoardSyncService`:

- `link(TaskBoard, TaskProviderInterface, ProviderCredential, RemoteContainer, object $actor, array $options)` links
  the board to a remote list (one per board).
- `runLinked(TaskBoard, SyncOptions)` runs the linked board with the host's credential, its stored options and its
  cursor.
- `run(TaskBoard, TaskProviderInterface, ProviderCredential, string $listId, SyncOptions, ?SyncCursor)` is the low-level
  call (it links the board on first use).
- `unlink(TaskBoard, object $actor)` removes the link and its sync state; the tasks, their import links and the stored
  files stay.
- `requestCancel(TaskBoard, string $runId)`.

A run:

1. takes the board's run lock (a second run gets status `busy` and writes nothing; a lock without heartbeat for 10 minutes
   is taken over);
2. reads the tasks page by page — every task on the first run (`initial`) or with `full`, else those updated since the
   last successful run minus two minutes (`incremental`);
3. writes each page in one transaction (after asking the host's `limit_checker`; a refusal writes nothing of the page),
   then copies the files and comments of the tasks it created or changed;
4. links the parents and Gantt dependencies whose other task came on a later page;
5. compares a complete list of the remote ids with the linked tasks (deletions, below);
6. records the run (history: one row per run, kept `history_keep` per board), the watermark and `SYNC_FINISHED`.

`SyncOptions::$maxPages` / `$timeBudgetSeconds` (and `sync.max_tasks_per_run`) end a slice with status `paused` and a
cursor; a 429 that asks to wait longer than `sync.http.max_inline_wait` ends it with `rate_limited`. The next
`runLinked()` resumes from the cursor under the same run id. Every run clears the entity manager after each page, so
entities loaded before the call are detached afterwards.

A run never reads more than `sync.max_pages_per_run` task pages (1 000 by default) over all its slices, and a slice at
most
`ceil(max_tasks_per_run / 100) + 1`; past either cap the run fails with `response_too_large` and its cursor is
cleared. A page that brings no new task ends the list with the warning `paging_stopped`: the run is reported as
succeeded with that warning, and the watermark is not moved, so the next run reads again from where this one started.
The list's task count is not used as a cap (audit EG-L7): ClickUp's `task_count` may leave out the subtasks and closed
tasks the pages hold.

Identity: a synced task carries the same task link a file import writes (`import_source` = `clickup`, `external_id` =
the ClickUp task id). A board that was imported from a ClickUp CSV or JSON file is adopted by its first sync without
duplicates, and a file re-import after a sync matches the same tasks. A remote id already imported into another board of
the tenant is skipped (counted, not named).

## Field rules and conflicts

For each synced field (title, description, status/column, priority, start, due, estimate, tags, assignees, parent) the
bundle keeps the hash of the value it last wrote (`task_board_sync_items.field_hashes`):

| Situation | Result |
| --- | --- |
| No baseline yet (adoption of a file import) | the provider wins where it has a value |
| The provider did not change the field | the local value stays, edited or not |
| The provider changed it, nobody changed it here | the provider's value is written (with a history entry) |
| Both changed it to the same value | nothing to write |
| **Both changed it differently** | **the local value stays**; one conflict note (a comment by the run's user) and a `sync_conflict` history entry list the provider's values; the run summary lists the conflicts too |

A conflict on the same remote value is noted once. An empty remote value never clears a local one. Comments are imported
once per remote id and updated when the provider edits them, unless they were edited here (a conflict on "comment").
Custom fields are typed values since 1.27.0 (`sync.custom_fields.mode`); with `text` or `both` they also go to a task
document "Fields (ClickUp)" that the sync keeps up to date.

## Deletions and the mass-archive guard

Tasks are never deleted by a sync. A linked task the provider no longer lists (deleted, archived or moved to another
list) is **archived** (`archive_reason` = `remote`); it is **restored** when it comes back. A task archived by a person
stays archived. A task deleted here is never recreated (it is counted as "skipped (deleted here)").

The guard: when a complete scan of the list returns no id at all, or would archive more than
`sync.max_archive_ratio` (default 0.5) of the linked tasks, nothing is archived and the run reports
`mass_archive_guard`. The connection page then shows a translated refusal and, to users who may manage the connection,
a *Refresh and archive anyway…* button behind a confirm dialog (its own CSRF token). The console has
`--allow-mass-archive`. A scan that fails never archives anything (`deletions_skipped`).

Archived tasks are hidden from the board, the list, the Gantt and the counts. The list view shows them in an "Archived
tasks" section with a *Restore* button (any archive reason; route `task_restore`).

## Files and images

Attachments and the images of descriptions and comments are downloaded by the bundle (`RemoteFileFetcher`) and handed
to the host's `attachment_storage`; a remote image is never hot-linked. The fetcher:

- accepts https URLs on the provider's attachment hosts only (exact or `*.suffix`), without user info, port or IP
  literal;
- refuses private, loopback, link-local and metadata addresses after DNS (`NoPrivateNetworkHttpClient`; with
  `nowo-tech/outbound-url-guard-bundle` installed its guard also checks and pins the address);
- follows at most `sync.attachments.max_redirects` redirects itself, each one checked again;
- never sends a credential to a file host;
- caps the size (declared, `Content-Length` and while streaming) at `sync.attachments.max_bytes` and the run at
  `max_bytes_per_run`;
- sniffs the type from the bytes: it must be in `allowed_mime_types`; SVG, HTML, scripts and executables are always
  refused.

Stored files appear on the task as links of type `attachment` (excluded from the link form). A file whose remote id,
size and date did not change is reused.

## Running it in the background

`InlineSyncRunner` (default) runs one slice of `sync.inline_slice_pages` pages in the request; the progress endpoint
continues a paused run, one slice per poll. A host with workers implements `SyncRunnerInterface::dispatch(SyncRequest)`
(ids and flags only) and calls `runLinked()` in its handler:

```php
final class MessengerSyncRunner implements SyncRunnerInterface
{
    public function dispatch(SyncRequest $request): void
    {
        $this->bus->dispatch(new SyncBoard($request->boardId, $request->actorId, $request->full, $request->allowMassArchive));
    }

    public function advancesOnPoll(): bool
    {
        return false; // the worker runs it
    }
}
```

In the handler, resolve the actor through `SyncActorProviderInterface` and stop when it answers `null`, then run:

```php
$actor = $this->actors->actorFor(TaskBoardSnapshot::of($board), $message->actorId); // SyncActorProviderInterface (TaskBoardSnapshot::of() since 3.0)
if ($actor === null) {
    return; // disabled, erased or refused: never run as anyone else
}
$report = $this->sync->runLinked($board, new SyncOptions(actor: $actor, trigger: SyncTrigger::Schedule, maxPages: 20));
```

Re-dispatch with a delay while `$report->status->isResumable()` (`$report->retryAfterSeconds` after a rate limit). A
handler that loads the user itself (e.g. `$em->find(User::class, $message->actorId)`) bypasses the actor refusal
(disabled, erased or `actor_checker`-refused users) and must not be used.

## Console command

```bash
php bin/console nowo:task-board:sync <boardId> [--full] [--dry-run] [--allow-mass-archive] [--time-entries] [--restart] [--format=text|json]
```

It runs to completion. Exit codes: 0 succeeded, 1 failed, 2 invalid (board not found, not linked, no user), 3 paused or
rate-limited (resumable), 4 refused by a limit, 5 busy. It never prints a token or a URL.

## UI

- Board, list and Gantt headers: *Connect provider* for managers; once linked, the badge "ClickUp · <list>", the last
  sync time and, for managers, *Refresh from ClickUp* and *Connection* (block `nowo_task_board_connection`, template
  `_connection_badge.html.twig`). Readers see the badge only. Since 1.25.2, `sync.badge.connect_link: false` drops
  the *Connect provider* link: an unlinked board shows nothing in the header (the connection page stays reachable at
  `board_connection` for the host to link from elsewhere); a linked board is unchanged. Since 1.26.3 the badge data
  carries the running run (`activeRunId`, `progressUrl` for managers, `total`, `processed`, `pollInterval`) and
  `sync.badge.progress: true` shows a determinate bar driven by the progress endpoint ([CONFIGURATION](CONFIGURATION.md#provider-sync-since-123)).
- The connection page (`board_connection`, template `templates.connection`): provider, stored connection (with a link
  to the host's credential form), the container browser, the import options; once linked, status, progress, history,
  *Refresh*, the mass-archive confirmation and *Unlink*.
- The container browser follows the APG disclosure pattern with a radio group (not `role="tree"`): containers are
  buttons with `aria-expanded` / `aria-controls`, their level is fetched on first open (`aria-busy` and a polite live
  region), lists are radios. Without script the same buttons submit and the page reloads with the level open.
- Progress: a `<progress>` (indeterminate without a total) and a polite, atomic live region updated at most every 5 s
  by polling `board_connection_progress` (JSON, `Cache-Control: no-store`, counters only).
- Every action is a POST with its own CSRF token. Users who may not manage the connection get 404 on these routes.

Who may manage: `TaskBoardResourceAccessChecker::canManageBoardConnection()` — `canEditBoard()` and the
`BOARD_ACCESS_CHECK` event dispatched with `BoardAccessCheckEvent::ACTION_MANAGE_CONNECTION` (default: the board's
creator or a `security.manage_roles` holder). **A listener that grants every action without looking at
`getAction()` widens it to everyone who can edit the board.**

```php
public function onBoardAccess(BoardAccessCheckEvent $event): void
{
    if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE_CONNECTION) {
        $this->canManageTasks($event->getSubject(), $event->getBoard()) ? $event->grant() : $event->deny();

        return;
    }
    // … the view decision
}
```

## People directory (since 1.25)

Every remote person (ClickUp user) is one row of a **directory** per (host scope, provider, workspace): every board and
connection of that scope shares it, so a remote identity maps to at most one local user per scope (several remote
identities may map to the same user: old and new e-mails). The scope comes from
`SyncPersonDirectoryInterface::directoryScope()` (`[a-z0-9:_-]{1,64}`, e.g. `org:<uuid>`; the default resolver uses
`board:<id>`, no sharing). The directory is attached on the first run of a connection (the workspace is looked up once).

| State | Meaning | Projected |
| --- | --- | --- |
| `unmatched` | No user; content stays on the run's actor, with the person reference | no |
| `suggested` | One candidate matched by e-mail, account **not verified**; needs a confirmation | no |
| `auto` | One verified candidate matched by e-mail | yes |
| `manual` | A human chose the user (`applyMapping()`, kind `manual` or `confirm`) | yes |
| `external` | A human chose *Keep as external person* (or `detachUser()`) | no |

**Automatic matching** runs when people are seen (the workspace members at the start of a run, refs in tasks,
comments, checklists and time, and a persisting pre-scan) for persons no human decided. The bundle normalises the
e-mail (trim, NFC when `ext-intl` is loaded, lower case) and asks `autoMatch()` for exactly one **candidate**: verified
→ `auto`, unverified → `suggested`, none → `unmatched`. A person without e-mail is never matched. The bundle passes the
normalised e-mail as the optional third argument `$normalizedEmail`; a host resolver called with two arguments (the
contract's signature) derives it with `SyncUser::normalizeEmail($person->email)`. Name and e-mail are
refreshed on every run; a changed e-mail sets `email_changed_at`. `manual` and `external` are never overridden; an
`auto` person whose e-mail changed (or whose user is gone) becomes `unmatched` with the warning
`person_auto_unmatched`, and nothing already imported moves. An anonymised person is never refreshed or matched again.
Every mapping change, automatic ones included, dispatches `SYNC_PERSON_MAPPED`.

**Projection rule — a mapping never grants access.** Before writing a mapped user as creator, member or comment author,
the writer checks `canViewBoard($user, $board)`. A user who cannot view the board is not written: the actor (creator,
comment author) plus the person reference is, and the person is listed in the item's `unmatched_people`. The bundle's
default access checker grants a task to its creator and members, so projecting would grant access. The People step can
still map such a person (warning `ScannedPerson::$boardAccess = false`); the templates show a *no access to this board*
badge, and the host still receives their time through the sink.

**Losing access deletes nothing.** Projected rows stay when a user loses board access; access to imported tasks follows
board access only if the host's access checker denies non-members. A host whose checker does not should call
`reassign($person, null)` to detach the content. When a user **gains** access, the host calls
`TaskSyncPeopleProjector::reprojectForUser($user, ?$board, $batchSize, $cursor, $trigger)` from its membership events:
references become rows where the user can view the board now (access and candidacy re-checked inside each board's lock);
idempotent; `SYNC_PEOPLE_REPROJECTED` once per finished call.

Who may manage the people (`applyMapping()`, `markReviewed()`, `anonymise()`, `reassign()`, the scans):
`canManageBoardConnection()` on **every** board of the directory, else `PeopleMappingDeniedException` (routes: 404).
Target users must be candidates of every board (`isCandidate()`, `candidateById()` is the only id lookup).
`SyncOptions::$requirePeopleMapping` refuses a first import (`people_mapping_required`) while someone is undecided and
the People step was not saved (`markReviewed()`).

Privacy: `anonymise()` turns the name into "Former <provider> user #n" (stored as the token `former:<n>`, translated at
display) and clears the e-mail; references stay. `pruneDirectory()` (run on the last unlink) deletes unreferenced
people and anonymises the rest. Export: `TaskSyncPersonRepository::exportFor($user)` / `exportDirectory($directory)`.

**People page queries:** `TaskSyncPersonRepository::forDirectory($directory, PersonFilter $filter, $limit, $offset)`
with `new PersonFilter(mappings: [...], q: 'text', changed: true, changedSince: $date)`: mapping states (empty =
all), `q` matches part of the name or the e-mail (case-insensitive, at most 120 characters, LIKE wildcards literal),
`changed` keeps people whose e-mail changed in the provider, `changedSince` those whose e-mail changed at or after the
date. `PersonFilter::mappings(TaskSyncPersonMapping::Unmatched, ...)` is the shortcut for states only.
No avatar URL is stored.

## Creator, assignees, watchers, checklists and comments (since 1.25)

- **Creator and date.** A new task's creator is the projected user of the remote creator, else the actor;
  `task_board_sync_items.creator_person_id` keeps the person either way. `Task::createdAt` is the provider's creation
  date (2000-01-01 … now + 5 min). Updates never move either, except the one-off back-fill of tasks written before 1.25
  (`backfill_level` 0 → 1 on their next fetch).
- **Assignees and watchers.** Refs go through the directory; projected users become `TaskMember`s and every member the
  sync adds gets a `task_board_sync_members` row. Only those members are removed when the person leaves the remote set;
  members added by hand stay. Unprojected people show as *Also assigned in ClickUp* / *Also watching in ClickUp*. No
  `TaskMemberAddedEvent` is dispatched (no notification during a sync). When a list row has no `watchers` /
  `checklists` key, `GET task/{id}` (E5) completes it for tasks created or changed in the run, at most
  `sync.watchers.max_detail_calls_per_run` per run.
- **Per run:** `SyncOptions::$watchers` / `$checklists` (default `true`) leave watchers or checklists out of one run;
  both also need their `sync.*.enabled` option. A run without either asks no task detail (E5) for it.
- **Checklists** (`sync.checklists.enabled`). Each item is a subtask of its task (external id `<task>#<item>`, tag
  `checklist:<name>`, checklist order then item order, nested items flattened in tree order). Resolved items are done:
  they go to the column the status map gives the first `closed` (else `done`) status, else the last column. The item
  assignee goes through the directory. The deletions scan never archives checklist items; an item is archived when its
  task was fetched with its checklists and the item is gone. An item deleted here is never recreated.
- **Comments.** The author is the projected user, else the actor, with `task_board_sync_comments.author_person_id`; no
  prefix line (the comment template renders *name (ClickUp)*), and the comment keeps its ClickUp date.
  `TaskSyncCommentLegacyAuthorMigrator::migrate($connection, $batchSize, $afterId)` returns a `MigrateReport`
  (`connectionId`, `directoryId`, `migrated`, `skipped`, `lastId`, `done`) and removes the 1.23 prefix line once
  where the name matches exactly one person (see [UPGRADING](UPGRADING.md#after-the-update-sync-hosts)).
- **Twig:** `task_board_sync_creator(task)`, `task_board_sync_comment_author(comment)`,
  `task_board_sync_unmatched(task, 'assignee'|'watcher')` return `SyncPersonView` (`name`, `provider`, `external`,
  `anonymised`, `boardAccess`); `task_board_sync_token()` renders the `person:` / `user:` tokens of the
  `sync_person_reassociated` history entries.

## Statuses and sources (since 1.25)

**Done comes from the status type.** A task whose ClickUp status type is `closed` or `done` is completed; one that
goes back to an open type is reopened (under the per-field rule of `column`). The 1.23 rule (completed in the last
column) applies only when the provider gives no status type.

**Status → column map.** `link(..., $statusColumnMap)` and `updateStatusColumnMap($board, $map)` take
`list<{status, column, type?}>`: `column` is a column id of the board, `__create` (a column named like the status,
created when the link is confirmed, in the statuses' order) or `__skip` (tasks in that status are not imported;
counted `skipStatus`). Statuses match ignoring case, accents and spaces. A status missing from the map follows the 1.23
rules (same-named column, create missing columns, fallback column) with the warning `unmapped_status`. `type` (from the
pre-scan) tells which column receives done checklist items.

**Sources.** A connection follows a list (1.23), a folder (`f:<id>`) or a space (`s:<id>`) through
`SourceProviderInterface`: `findSource()`, `fetchSourceTasks()` (ClickUp `GET team/{id}/task` with
`project_ids[]` for a folder or `space_ids[]`; a list keeps the 1.23 calls), `existingSourceTaskIds()`, `listStatuses()` (list or space statuses; for a folder its
own, else the union of its lists'), `resolveListFromUrl()` (only `https://app.clickup.com/…` list links; no network
call) and `searchLists()` (at most 40 calls; cached 300 s in `sync.lookup.cache_pool`, keyed by a hash of the connection
reference, never the token). With `sync.list_as_tag` tasks of a multi-list source get the tag `list:<name>`.

### Columns no status uses (since 1.28.0)

With `unmatchedColumns: delete`, at the end of the first import, each board column that no provider status maps to is deleted if it is
empty. A status maps through the dialog's map (`__skip` maps to nothing), else by the same name; the fallback column counts as mapped.
Whatever cannot be decided keeps the column and says so in the run report: a source not read to the end, edit permission lost during
the run, statuses the provider did not give, a database refusal. The decision is marked on the connection before anything is deleted, so
a failure is never retried by a later sync. A run that fails is not the end of the first import: the retry still decides.

## Tracked time (since 1.25)

The bundle **never writes TimeTrack data**. With `sync.time_entries.sink` set and `SyncOptions::$timeEntries`, a
`time_entries` phase runs after the deletions:

- **Admin token** (workspace owner or admin): every directory person's entries, in `assignee` chunks of
  `max_assignees_per_call` (provider ids from the directory only). **ClickUp returns other people's time only to
  workspace owners and admins.**
- **Member token:** only the token user's own entries, with the warning `time_member_mode`. With
  `SyncOptions::$timeFallbackPerTask` (explicit, never automatic) the legacy per-task intervals (E8) are read for up to
  `fallback_max_tasks_per_run` linked tasks; when the provider no longer serves them, `time_fallback_unavailable`.
- **Windows** of `window_days` (≤ 30) from `SyncOptions::$timeFrom` (default: the board's oldest task, else 30 days
  back), at most `max_windows_per_run` per run; a window with `max_entries_per_window` rows or more is halved and read
  again, down to one hour (then `response_too_large`). The phase resumes per window and chunk.
- **The sink** gets `accept($board, $directory, $window, $access, $entries, $windowComplete)` once per window and chunk:
  `ImportedTimeEntryCandidate {entry, person, ?task}` (`task` null when the remote task is not linked on this board).
  Running timers and entries without a task are counted (`$report->time->skippedRunning`, `skippedTaskless`), never
  delivered. `$windowComplete` is true on the window's last chunk: the host may tombstone what it had for that window.
  Beacon stages the entries and writes them with TimeTrack's `ImportedTimeEntryWriter` under
  `externalSource = clickup:<directory id>`.
- **Report:** `SyncReport::$time` (`TimeCounts`: `mode` admin / member / fallback or null, `windows`, `windowsSplit`,
  `entries`, `skippedRunning`, `skippedTaskless`, `fallbackTasks`) and `SyncReport::$people` (`PeopleCounts`: `seen`,
  `created`, `autoMatched`, `suggested`, `unmatchedNew`, `changedEmail`, `autoUnmatched`). Other counters
  (`checklist.*`, `detail.calls`, `skipStatus`) stay in `SyncReport::$stats` / `stat()`; the typed ones are not
  repeated there.

**TO CONFIRM** against a real workspace (handled defensively): the row cap per answer, the `assignee` list length,
whether guests can track time, and what a member token gets when `assignee` lists others (the adapter sends none).

### Hours of people with no user (since 1.28.0)

A plain sink gets people mapped to a user, and the rest is counted (`time.skippedUnmapped`, shown with its hours in the
completeness report). A sink that implements `OwnerlessTimeEntryReceiverInterface` also gets those entries, with `user()` null and the
person on the candidate; the bundle stores no time and the hours stay at the provider either way. `ownerless.entries` counts them.

## Pre-scans and re-association (since 1.25)

- `TaskSyncSourceScanner::scan($board, $provider, $credential, RemoteLocation $source, $actor, $persistPeople = true)`
  (the connection dialog, before `link()`): the source, its workspace, its statuses with types, the people scan and
  the time mode. Read-only on the provider; with `$persistPeople` the draft directory is filled.
- `TaskSyncPeopleScanner::scan($board, $provider, $credential, $workspaceId, $location, $actor, $persist)`: members,
  the first task page(s) (`sync.people.scan.max_task_pages`), the comments of the first tasks (`max_comment_tasks`)
  and, with an admin token, one 30-day time window (`time_window`); at most 15 calls. Each `ScannedPerson` has the
  counts per kind, the mapping, the match (`auto` / `suggested` / `none`), the matched user id and `boardAccess`.
- `TaskSyncPeopleReassigner::reassign($person, ?$newUser, $actor, $batchSize, $cursor)` maps the person to a
  candidate (or external) and moves creator, comment authors and sync members (checklist subtasks included) on every
  board of the directory, under each board's lock (`busyBoards` when held). Call again with the returned cursor until
  it is null. The pending target is stored on the person: a restart resumes, another target is refused
  (`ReassignInProgressException`) until it finishes or `cancelReassign()`. One `sync_person_reassociated` history
  entry per task; `SYNC_PERSON_REASSIGNED` when done (the host moves its own data there, e.g. TimeTrack copies).
- `detachUser($user, $actor, $anonymise, …)` is the account-erasure helper: every person mapped or suggested to the
  user becomes external (optionally anonymised) and their content goes back to the actor plus the reference. Run it
  **before** deleting the user ([erasure order](UPGRADING.md#after-the-update-sync-hosts)).

## People page and step (since 1.27.0)

TB-SYNC-2: a UI on top of the people directory, so a manager decides who each remote person is.

- **People page** (`board_connection_people`, template `templates.connection_people`; linked from the connection page
  by `templates.connection_people_summary`: "*n* of *m* people are mapped. *k* not mapped yet." and *Map people*). One
  row per remote person of the connection's directory (workspace members and every creator, assignee, watcher,
  commenter, checklist and time-entry user a run or scan saw), by name, `page_size` per page, filter *All* /
  *Not mapped yet*. Each row has a host-user select: *Not mapped*, the board's candidates
  (`SyncPersonDirectoryInterface::candidates()`, at most `candidate_limit`) and, with `sync.people.inviter` and a
  remote e-mail, *Invite by e-mail*. No remote avatar is shown (none is stored): an initial instead.
- **Pre-fill, deny by default.** The select starts on the current mapping; else the host's suggestion (unverified
  e-mail match); else the only candidate with the same normalised e-mail. The only candidate whose label (`UserLabel`) has the
  same normalised name (case, accents, spaces, `.`, `_`, `-` ignored) is **suggested beside a select left on *Not mapped***,
  because a remote person chooses their own name. A pre-fill is only a starting value: **nobody is mapped until a manager saves**, and only to a candidate of
  every board of the directory. A sync still maps automatically only the 1.25 verified e-mail matches.
- **Stale pages**: a row absent from the POST stays as it is.
- **Save** (`board_connection_people_save`, CSRF token id `task_board_connection_people`, form
  `ConnectionPeopleFormType`, fields `p_<person id>`; any other value is a form error): rows whose value changed go
  through `TaskSyncPeopleReassigner::reassign()`, so the tasks already imported follow — creator, assignees and watchers
  the sync added, comment authors (the comment keeps the remote name as its snapshot), checklist items — on every board
  of the directory; the host moves its tracked time on `SYNC_PERSON_REASSIGNED`, and the time phase sends mapped people's
  entries from then on. *Not mapped* on a mapped person keeps them as an external person (their sync rows go back to
  references). A save spends at most `reapply_seconds`; what is left is pending (the page says so) and continues on the
  next save and **at the end of every sync of a board of the directory** (`PeopleMappingService::resume()`). The save
  marks the directory reviewed (`SYNC_PEOPLE_REVIEWED`) and dispatches **`SYNC_PEOPLE_MAPPING_CHANGED`**
  (`PeopleMappingChangedEvent`: board id, directory id, actor id, the changes as ids and mapping states, invitations,
  pending) once, besides the per-person `SYNC_PERSON_MAPPED` / `SYNC_PERSON_REASSIGNED`.
- **Invite by e-mail** (`Sync\Hook\PeopleInvitationInterface`: `canInvite($board, $actor)`, `invite($board, $email, $name,
  $actor): ?object`): a returned user is mapped right away (still checked as a candidate); null = pending invitation, the
  person stays unmapped until a later sync matches the verified account.
- **Step** (`sync.people.step: true`): linking a board attaches the directory and runs the people pre-scan
  (`TaskSyncPeopleScanner`, ≤ 15 calls) instead of starting the import, and redirects to the People page, whose buttons
  are *Save and start the import* and *Start the import without mapping*. Without a people capability (no workspace),
  or when the scan fails (flash with the provider error), the import starts at once as before.
- **Run summary.** The connection page shows the people line; the progress JSON gains `unmappedPeople` (people nobody
  decided: unmatched or suggested).
- **Access.** Every People route needs `canManageBoardConnection()` (`ACTION_MANAGE_CONNECTION`, deny by default) on
  the board and on every board sharing the directory, else 404: remote e-mails are only shown to those managers.
- **Scope.** The mapping is stored per directory: per connection by default (`board:<id>`), or per provider workspace
  and host tenant when the host's `directoryScope()` returns a shared scope.

### Former people and attribution only (since 1.28.0)

`sync.people_bulk_former` adds a button marking everyone undecided (unmatched or suggested, not anonymised, across all pages) as a
former person: mapping `external`. Their name stays on tasks, comments and history; they cannot be assigned, mentioned or notified,
and nothing moves. A person remains changeable, and anonymising stays available.

`AttributionOnlyPolicyInterface` lets a host offer a user for attribution only. The option says so in the select. Such a mapping
writes no membership and does not set the task's creator or a comment's author, because a task's creator gets access through
`isTaskParticipant`. The panel shows the user's label from the view model, and the sink receives the user for tracked time.

## Everything a ClickUp task has (since 1.27.0)

TB-SYNC-3. Remote data is untrusted everywhere: values are type-checked, text is sanitised and cut, links must be
`http(s)`, files go through `RemoteFileFetcher` (provider attachment hosts only, no private addresses, size caps, type
sniffing), and nothing remote is rendered unescaped.

### Typed custom fields

`sync.custom_fields.mode`: `typed` (default), `text` (the 1.23 Markdown document only) or `both`.

- **Definitions** per board (`task_board_sync_fields`: provider field id, name, type, remote type, choices, config,
  position), refreshed when the provider renames or retypes a field. **Values** per task
  (`task_board_sync_field_values`: the typed value as JSON and a one-line text). The provider is their source: a value
  it drops is removed; there is no local editing. A re-sync writes nothing when nothing changed.
- **Types** (`Sync\Field\SyncFieldType`): text (ClickUp `text`, `short_text`), number, money (`currency`, ISO code),
  date (`date`, with time when the field says so; UTC), dropdown (`drop_down`), labels, checkbox, url, email, phone,
  rating (`emoji`, 1–10 stars), progress (`automatic_progress` / `manual_progress`, 0–100), people (`users`, stored as
  references to the directory) and relationship (`tasks`, `list_relationship`). **Any other type, and any value that does
  not fit its type, is kept as text** (e.g. a `javascript:` URL shows as text, never as a link); the field keeps its type
  and the view re-checks a value before drawing a link. The one exception is a malformed people value, which is left out:
  a name goes only through the directory.
- **Leaving types out**: `sync.custom_fields.exclude_types` (default none). Excluded types are not read into the typed
  values or the "Fields" document; what is stored of them goes when the task is next written.
- **Erasure and access**: see [SECURITY](SECURITY.md#personal-data-in-synced-tasks-rgpd--lopdgdd). In `text` or `both` mode
  the "Fields" document holds the values as plain text (a `users` value as names), which neither `anonymise()` nor the
  eraser reaches; prefer `typed`.
- **Task panel**: a *Fields* section (`_task_sync_fields.html.twig`, Twig `task_board_sync_fields(task)`): choices as
  badges (`data-color`), checkbox Yes/No, url as an external link (`rel="noopener noreferrer nofollow"`), email
  `mailto:`, rating as stars with an accessible label, progress as `<progress>`.
- **List view**: each row carries `data-search-extra` (Twig `task_board_sync_search_texts(tasks)`, only the rows drawn, once per group
  and request): the list search finds tasks by their field values.
- Report counters (`SyncReport::stat()`): `fields.defined`, `fields.values`, `fields.cleared`.

### Comments in full

- **Threads**: replies (`GET comment/{id}/reply`, read for comments with `reply_count > 0`; the replies count against
  `sync.max_comments_per_task`) follow their comment, oldest first. `task_board_sync_comments.parent_remote_id`; the
  comment shows *Reply to <name>*.
- **Files**: the attachment segments of a comment are copied like task attachments (same checks and budgets) and
  listed under the comment body (*Attachments:* with links to the host's stored copies; a refused file is named
  without a link). The comment is rewritten when its text or its files change.
- **Assigned comments (action items)**: the assignee (through the people directory) and the resolved state
  (`assignee_person_id`, `resolved`), shown as *Assigned to <name>* and *Resolved* / *Open* badges; they are
  refreshed on every fetch without rewriting the comment.
- **Edit date**: `remote_updated_at` (*edited in the provider on …*).
- Twig `task_board_sync_comment_meta(comment)`: `reply`, `replyTo`, `resolved`, `assignee`, `remoteEditedAt`.
- Not imported: reactions (optional in the request).
- **TO CONFIRM** against a real workspace: the reply endpoint and the field names `assignee`, `resolved`,
  `date_updated` and the `attachment` segment shape.

**Style (since 1.28.0).** A comment's segments become GitHub-flavoured Markdown. Block attributes (`list`, `indent`,
`code-block`, and Quill's `blockquote` and `header`) count on the line feed that ends a line, which is what ClickUp
documents, and on the line's text, in the nested (`{"list": {"list": "bullet"}}`) and the flat form. A task mention links
to `https://app.clickup.com/t/<id>` when the segment has no address of its own; it does not point at the imported task
here. `underline`, `color` and `background-color` have no Markdown form: the text stays and the style does not.

Descriptions come from `markdown_description` through `RemoteContentSanitizer`: a GFM table stays a table, and anything
that looks like an HTML tag stays visible as text (`\<u>`, rendered `<u>`), in a table cell too. The sanitizer also
escapes `<` inside inline code spans, so a span shows its backslash there: block code keeps its text exactly.

### Dates, points, links, ids, location, status history, archived tasks, recurrence

Kept read-only on the synced task (`task_board_sync_items.remote_meta`) and shown in the task panel's *From
ClickUp* section (`_task_sync_details.html.twig`, Twig `task_board_sync_details(task)`):

- **Custom task id** (`custom_id`, e.g. `DEV-123`, column `task_board_sync_items.custom_id`), also found by the list search.
- **Points** (`points`).
- **Done / closed dates** (`date_done`, `date_closed`, 2000-2100 only). The done (else closed) date becomes the task's
  completion date when the sync completed the task or the date has not changed here since the last sync; a date set here
  is kept (`details.completionKept`).
- **Dates with time**: `start_date_time` / `due_date_time` say whether start and due carry a time of day (the dates
  themselves are the task's start and due, as before; ClickUp dates are UTC instants).
- **Location**: the folder (unless hidden) and list names.
- **Estimate per assignee** (`time_estimates_by_user`, TO CONFIRM against a live workspace). Stored as a reference to
  the person (`Sync\People\PersonToken`), not a name: `anonymise()` covers it; without a directory it shows `#<id>`.
- **Recurrence**: a read-only text when the answer has one (`recurrence`; the v2 task answer usually has none, see the
  completeness report).
- **Time in status** (`GET task/{id}/time_in_status`, `Sync\Provider\StatusHistoryProviderInterface`): for the tasks
  a run creates or changes, at most `sync.status_history.max_calls_per_run` per run; a table of status, since and
  minutes. When ClickUp does not serve it (404 / 410, or another lasting error such as the 400 of a disabled ClickApp,
  counted as `statusHistory.failed`) the last one stays and the run goes on. A 403 stops the requests for the rest of the
  run (`statusHistory.forbidden`, `statusHistory.skipped`), and the report shows `time_in_status` as *Unavailable*. A passing error (rate limit, timeout, 5xx,
  refused token) stops the run as usual and the task is read again by the next one. A task past the budget gets its
  history when it next changes, or on a full sync.
- **Linked tasks** (`linked_tasks`, non-blocking) become `related` relations to the other task (in the Gantt), like
  dependencies; the file import maps them the same way.
- **Archived tasks** (`sync.import_archived`, default `true`): after the active pages, the archived ones are read
  (ClickUp `archived=true`, cursor `a<n>`, at least one more request per run) and imported archived (reason `remote`).
  They do not count against the host's limit of new tasks (`SyncQuotaPolicyInterface`), which is about active tasks;
  every restore, by a sync or by hand, asks it. The id scan
  of deletions still reads active tasks only.
- Watchers are mapped to host users through the People mapping (since 1.25); description images are stored and
  rewritten (since 1.23); attachments keep the provider's order.
- **Task files**: ClickUp list rows have no `attachments`, so the task's detail (`GET task/{id}`) is read within
  `sync.watchers.max_detail_calls_per_run`. A task past the budget is counted as `attachments.unread` and shown as *Not
  read*. A version already read spends no request, whatever else the row lacks, so repeated full syncs get through a large
  board. A lasting error on the detail (403, too large) leaves that task as it is, the run goes on, and it is listed as
  `task_detail`, *Unavailable*; a passing one stops the run as usual.

**Since 1.28.0:** the task type (`custom_item_id`, whose 0 is ClickUp's own *Task*, with the workspace's name for the rest; `#<id>` if the names cannot
be read), all the task's lists, and tag colours (plain hex only) are in the panel. Subtasks at any depth arrive flat with their `parent`, closed ones
included (`subtasks=true`, `include_closed=true`). `sync.include_multi_list_tasks` sends `include_timl` on list sources; ClickUp documents it only on
that endpoint, so a folder or space source does not send it. Columns the connection created take their status's colour and the provider's order among
themselves at the end of the first import; every other column stays where it is. A later run orders and colours only the columns it creates itself.

### Completeness report

After each run the connection page lists, per key of the provider's task answers that carried a value, on how many
tasks and whether it is imported, skipped (with the provider's reason: not supported, not needed, internal) or **Not
imported yet** (a key the provider does not declare; listed in the summary line). Every task the run read counts,
written or not; an incremental run reads the tasks changed since the last one, so a full sync gives the whole picture.
What is still unread is listed above the rest, from what each task keeps (`pending_reads`), so a later run does not hide
it: `attachments`, `comment_replies` and `task_detail` as *Not read* (request budget; a full sync reaches the next tasks),
`task_detail` as *Unavailable* (a lasting error). `time_in_status` is *Unavailable* when the provider refused the token in
the latest run.
The provider declares its keys with `Sync\Provider\FieldCoverageProviderInterface::fieldCoverage()`; the counts are the
run's `keys.<key>` stats (`RemoteTask::$presentKeys`, keys validated as `[a-z0-9_]{1,40}`, at most 200 keys per run:
`completeness.keys`). Only keys and counts are stored, never values.

**After upgrading**, run one full sync per linked board so that existing tasks get all of this
([UPGRADING](UPGRADING.md#to-1270)).

**What the API does not give (since 1.28.0).** `Sync\Provider\ProviderApiGapsInterface::apiGaps()` adds rows on every report, whatever the answers held. For
ClickUp, from its published reference as read on 2026-11-06: `reminders`, `sprints`, `goal_links`, `folder_description`, `space_description` are *not exposed*. No
reference page documents them on a task, a folder or a space. `comment_assigned_by` and `comment_reactions` are exposed and not kept. `task_types` is *Unavailable* when the names
were refused, and `time_in_status` stays *Unavailable* across runs until a later run reads it.

### Time entries one by one

Already since 1.25 (see [Tracked time](#tracked-time-since-125)): each entry (user through the People mapping, start,
end, duration, description, billable, tags) reaches the host's `sync.time_entries.sink` one by one as an
`ImportedTimeEntryCandidate`; since 1.27.0 `ImportedTimeEntryCandidate::user()` gives the mapped host user. The bundle
never deletes tracked time: not on a re-sync, not when a task, a board or a user is deleted. **Hosts that must never delete
imported time ignore `$windowComplete`** (it only says a window was read in full).

## Events

| Constant | Event | Payload |
| --- | --- | --- |
| `SYNC_STARTED` | `SyncStartedEvent` | board, run id, provider, mode, trigger, actor, dry run |
| `SYNC_PAGE_DONE` | `SyncPageDoneEvent` | board, the report so far |
| `SYNC_FINISHED` | `SyncFinishedEvent` | board, `SyncReport` (succeeded, paused, rate-limited, refused, cancelled) |
| `SYNC_FAILED` | `SyncFailedEvent` | board, `SyncError`, the report |
| `TASK_REMOTE_ARCHIVED` / `TASK_REMOTE_RESTORED` | `TaskRemoteArchivedEvent` / `TaskRemoteRestoredEvent` | task, provider, external id, run id |
| `BOARD_CONNECTION_LINKED` / `BOARD_CONNECTION_UNLINKED` | `BoardConnectionEvent` | board, provider, list, connection ref, actor |
| `SYNC_PERSON_MAPPED` (1.25) | `SyncPersonMappedEvent` | directory id, person id, previous / new mapping and user id, actor id (null for automatic), reason (`auto`, `suggested`, `manual`, `external`, `confirm`, `auto_unmatched`, `erased`) |
| `SYNC_PERSON_REASSIGNED` (1.25) | `SyncPersonReassignedEvent` | directory id, person id, previous / new user id, actor id, reassign id, `ReassignReport` |
| `SYNC_PERSON_ANONYMISED` (1.25) | `SyncPersonAnonymisedEvent` | directory id, person id, actor id |
| `SYNC_PEOPLE_REVIEWED` (1.25) | `SyncPeopleReviewedEvent` | directory id, actor id, counts per state |
| `SYNC_PEOPLE_REPROJECTED` (1.25) | `SyncPeopleReprojectedEvent` | user id, board ids, `ReassignReport`, trigger |
| `SYNC_PEOPLE_MAPPING_CHANGED` (1.27.0) | `PeopleMappingChangedEvent` | board id, directory id, actor id, changes (person id, previous / new mapping and user id), invited, pending |

No event carries a credential, an e-mail or a person name (people events carry ids; listeners must not log the person entities). Like file imports, a sync does not dispatch per-task `TASK_CREATED` / `TASK_UPDATED`.

## Testing with the in-memory provider

`Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider` is a scriptable provider (containers, tasks, comments, injected
failures) that never opens a connection. Register it under the id of the provider it replaces, e.g. in `when@test`:

```yaml
when@test:
    nowo_task_board:
        sync:
            providers: { clickup: { enabled: false } }
    services:
        app.fake_clickup:
            class: Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider
            arguments: { $providerId: clickup, $acceptedSecret: pk_test_token }
            tags: [nowo_task_board.task_provider]
```

The demo uses it (`demo/symfony8/src/Sync/DemoTaskProviderFactory.php`) and its Playwright suite runs the whole flow
with axe (`make -C demo/symfony8 test-e2e`).

## Adding a provider

Implement `Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface` (autoconfigured with the tag
`nowo_task_board.task_provider`): `listContainers()`, `findContainer()`, `fetchTasks()` (pages, `updatedSince`),
`fetchComments()`, `existingTaskIds()` (must throw rather than return a partial list), `capabilities()` (including the
attachment hosts) and `importScope()`. Use `ProviderHttpClient` for the API calls (fixed base URI, no redirects, rate
limits, caps) and `RemoteContentSanitizer` for the text, and never put the credential in an exception.
