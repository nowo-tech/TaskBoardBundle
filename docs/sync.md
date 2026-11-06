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
- [People directory (since 1.25)](#people-directory-since-125)
- [Creator, assignees, watchers, checklists and comments (since 1.25)](#creator-assignees-watchers-checklists-and-comments-since-125)
- [Statuses and sources (since 1.25)](#statuses-and-sources-since-125)
- [Tracked time (since 1.25)](#tracked-time-since-125)
- [Pre-scans and re-association (since 1.25)](#pre-scans-and-re-association-since-125)
- [People page and step (since 1.27.0)](#people-page-and-step-since-1270)
- [Everything a ClickUp task has (since 1.27.0)](#everything-a-clickup-task-has-since-1270)
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
| `people.resolver`: `SyncPersonResolverInterface` (since 1.25) | no | `DefaultSyncPersonResolver`: one directory per board, the board's assignable users as candidates, 1.23 e-mail lookup |
| `time_entries.sink`: `ImportedTimeEntrySinkInterface` (since 1.25) | no | none: the `time_entries` phase never runs |
| `BOARD_ACCESS_CHECK` listener for `import_tasks` (since 1.25) | no | granted: who may edit the board (and, for the connection, manage it) may import |

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
`SyncPersonResolverInterface::directoryScope()` (`[a-z0-9:_-]{1,64}`, e.g. `org:<uuid>`; the default resolver uses
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
  (`SyncPersonResolverInterface::candidates()`, at most `candidate_limit`) and, with `sync.people.inviter` and a
  remote e-mail, *Invite by e-mail*. No remote avatar is shown (none is stored): an initial instead.
- **Pre-fill, deny by default.** The select starts on the current mapping; else the host's suggestion (unverified
  e-mail match); else the only candidate with the same normalised e-mail; else the only candidate whose label
  (`UserLabel`) has the same normalised name (case, accents, spaces, `.`, `_`, `-` ignored), marked *Pre-filled by name:
  check it*. A pre-fill is only a starting value: **nobody is mapped until a manager saves**, and only to a candidate of
  every board of the directory. A sync still maps automatically only the 1.25 verified e-mail matches.
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
- **Invite by e-mail** (`Sync\Hook\PeopleInviterInterface`: `canInvite($board, $actor)`, `invite($board, $email, $name,
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
  rating (`emoji`, 1–10 stars), progress (`automatic_progress` / `manual_progress`, 0–100), people (`users`, names as
  given by ClickUp) and relationship (`tasks`, `list_relationship`). **Any other type, and any value that does not fit
  its type, is kept as text: nothing is dropped** (e.g. a `javascript:` URL shows as text, never as a link).
- **Task panel**: a *Fields* section (`_task_sync_fields.html.twig`, Twig `task_board_sync_fields(task)`): choices as
  badges (`data-color`), checkbox Yes/No, url as an external link (`rel="noopener noreferrer nofollow"`), email
  `mailto:`, rating as stars with an accessible label, progress as `<progress>`.
- **List view**: each row carries `data-search-extra` (Twig `task_board_sync_search_text(task)`, one query per board
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

### Dates, points, links, ids, location, status history, archived tasks, recurrence

Kept read-only on the synced task (`task_board_sync_items.remote_meta`) and shown in the task panel's *From
ClickUp* section (`_task_sync_details.html.twig`, Twig `task_board_sync_details(task)`):

- **Custom task id** (`custom_id`, e.g. `DEV-123`), also found by the list search.
- **Points** (`points`).
- **Done / closed dates** (`date_done`, `date_closed`). The done (else closed) date becomes the task's completion date
  when the task is completed.
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
  counted as `statusHistory.failed`) the last one stays and the run goes on. A passing error (rate limit, timeout, 5xx,
  refused token) stops the run as usual and the task is read again by the next one. A task past the budget gets its
  history when it next changes, or on a full sync.
- **Linked tasks** (`linked_tasks`, non-blocking) become `related` relations to the other task (in the Gantt), like
  dependencies; the file import maps them the same way.
- **Archived tasks** (`sync.import_archived`, default `true`): after the active pages, the archived ones are read
  (ClickUp `archived=true`, cursor `a<n>`, at least one more request per run) and imported archived (reason `remote`).
  They do not count against the host's limit of new tasks (`SyncLimitCheckerInterface`), as a restore does. The id scan
  of deletions still reads active tasks only.
- Watchers are mapped to host users through the People mapping (since 1.25); description images are stored and
  rewritten (since 1.23); attachments keep the provider's order.

### Completeness report

After each run the connection page lists, per key of the provider's task answers that carried a value, on how many
tasks and whether it is imported, skipped (with the provider's reason: not supported, not needed, internal) or **Not
imported yet** (a key the provider does not declare; listed in the summary line). Every task the run read counts,
written or not; an incremental run reads the tasks changed since the last one, so a full sync gives the whole picture.
The provider declares its keys with `Sync\Provider\FieldCoverageProviderInterface::fieldCoverage()`; the counts are the
run's `keys.<key>` stats (`RemoteTask::$presentKeys`, keys validated as `[a-z0-9_]{1,40}`, at most 200 keys per run:
`completeness.keys`). Only keys and counts are stored, never values.

**After upgrading**, run one full sync per linked board so that existing tasks get all of this
([UPGRADING](UPGRADING.md#unreleased)).

### Time entries one by one

Already since 1.25 (see [Tracked time](#tracked-time-since-125)): each entry (user through the People mapping, start,
end, duration, description, billable, tags) reaches the host's `sync.time_entries.sink` one by one as an
`ImportedTimeEntryCandidate`; since 1.27.0 `ImportedTimeEntryCandidate::user()` gives the mapped host user. The bundle
never deletes tracked time: not on a re-sync, not when a task or a board is deleted. **Hosts that must never delete
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
