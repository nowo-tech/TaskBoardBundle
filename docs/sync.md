# Provider connections and sync (since 1.23)

A board can follow a list of another task tool and keep it up to date: the first run imports every task, later runs
create the new ones, update the changed ones, archive the ones the provider deleted and restore the ones that come
back. ClickUp (API v2) is the first provider; the contract is ready for others.

The feature is **opt-in** (`sync.enabled: false` by default): without it there are no routes, no services, no Doctrine
mapping of the sync tables and no header change.

## Table of contents

- [What the host provides](#what-the-host-provides)
- [Configuration](#configuration)
- [How a run works](#how-a-run-works)
- [Field rules and conflicts](#field-rules-and-conflicts)
- [Deletions and the mass-archive guard](#deletions-and-the-mass-archive-guard)
- [Files and images](#files-and-images)
- [Running it in the background](#running-it-in-the-background)
- [Console command](#console-command)
- [UI](#ui)
- [Events](#events)
- [Testing with the in-memory provider](#testing-with-the-in-memory-provider)
- [Adding a provider](#adding-a-provider)

## What the host provides

The bundle **never stores a token**. The host keeps the credentials and gives one for each run.

| Hook (service id in `sync.*`) | Required | Default |
| --- | --- | --- |
| `credential_resolver`: `Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface` | **yes** | — (the container build fails without it) |
| `attachment_storage`: `TaskAttachmentStorageInterface` | no | files are counted as refused (`storage_disabled`) |
| `inline_image_rewriter`: `InlineImageRewriterInterface` | no | `MapInlineImageRewriter` (stored copy, else the text "[image not copied]") |
| `limit_checker`: `SyncLimitCheckerInterface` | no | no host limit |
| `runner`: `SyncRunnerInterface` | no | `InlineSyncRunner` (one slice per request; the progress page continues it) |
| `actor_resolver`: `SyncActorResolverInterface` | no | the user of the request, else the user who linked the board; since 1.24 a disabled user (`isEnabled()` false) or one `actor_checker` refuses is never the actor, and a named user that is refused, no longer found or fails to load is not replaced by the linker |
| `actor_checker`: Symfony `UserCheckerInterface` (since 1.24) | no | none: only `isEnabled()` is checked |

```php
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

final class ProjectConnections implements ProviderCredentialResolverInterface
{
    public function connectionsFor(TaskBoard $board, object $user, string $provider): array
    {
        // The stored connections this user may use on this board: an opaque ref, a label, the last 4 characters.
        return [new ConnectionChoice('42', 'Team token', 'a1b2')];
    }

    public function credentialFor(TaskBoard $board, string $connectionRef): ?ProviderCredential
    {
        // Decrypt the token for one run; null when the connection is gone or no longer allowed for this board.
        return new ProviderCredential($this->decryptedToken($connectionRef), $connectionRef);
    }

    public function credentialFormUrl(TaskBoard $board, string $provider): ?string
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
Custom fields go to a task document "Fields (ClickUp)" that the sync keeps up to date.

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

In the handler, resolve the actor through `SyncActorResolverInterface` and stop when it answers `null`, then run:

```php
$actor = $this->actors->actorFor($board, $message->actorId); // SyncActorResolverInterface
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
php bin/console nowo:task-board:sync <boardId> [--full] [--dry-run] [--allow-mass-archive] [--restart] [--format=text|json]
```

It runs to completion. Exit codes: 0 succeeded, 1 failed, 2 invalid (board not found, not linked, no user), 3 paused or
rate-limited (resumable), 4 refused by a limit, 5 busy. It never prints a token or a URL.

## UI

- Board, list and Gantt headers: *Connect provider* for managers; once linked, the badge "ClickUp · <list>", the last
  sync time and, for managers, *Refresh from ClickUp* and *Connection* (block `nowo_task_board_connection`, template
  `_connection_badge.html.twig`). Readers see the badge only.
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

## Events

| Constant | Event | Payload |
| --- | --- | --- |
| `SYNC_STARTED` | `SyncStartedEvent` | board, run id, provider, mode, trigger, actor, dry run |
| `SYNC_PAGE_DONE` | `SyncPageDoneEvent` | board, the report so far |
| `SYNC_FINISHED` | `SyncFinishedEvent` | board, `SyncReport` (succeeded, paused, rate-limited, refused, cancelled) |
| `SYNC_FAILED` | `SyncFailedEvent` | board, `SyncError`, the report |
| `TASK_REMOTE_ARCHIVED` / `TASK_REMOTE_RESTORED` | `TaskRemoteArchivedEvent` / `TaskRemoteRestoredEvent` | task, provider, external id, run id |
| `BOARD_CONNECTION_LINKED` / `BOARD_CONNECTION_UNLINKED` | `BoardConnectionEvent` | board, provider, list, connection ref, actor |

No event carries a credential. Like file imports, a sync does not dispatch per-task `TASK_CREATED` / `TASK_UPDATED`.

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
