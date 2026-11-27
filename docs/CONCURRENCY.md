# Concurrency (REQ-RUNTIME-003)

How the bundle's writes behave when two writers meet the same rows: what is locked, in which order, what a loser gets,
and which tests prove it on which databases. Worker mode (kernel not reset) is covered in
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md); the Task API's answers in [API.md](API.md).

## Table of contents

- [Databases tested](#databases-tested)
- [Rules every write follows](#rules-every-write-follows)
- [Writes inside a host's transaction](#writes-inside-a-hosts-transaction)
- [Lock order](#lock-order)
- [Questions on tasks (2.23)](#questions-on-tasks-223)
- [Task edit form (2.31)](#task-edit-form-231)
- [Task API writes](#task-api-writes)
- [Host ports by id (2.29)](#host-ports-by-id-229)
- [Lock conflicts and replays](#lock-conflicts-and-replays)
- [Remote sync, imports and the number backfill](#remote-sync-imports-and-the-number-backfill)
- [ClickUp OAuth helper (3.1)](#clickup-oauth-helper-31)
- [ClickUp import plans (3.2)](#clickup-import-plans-32)
- [The application's entity manager](#the-applications-entity-manager)
- [Residual risks](#residual-risks)
- [Tests](#tests)

## Databases tested

CI runs the functional suite on SQLite (in memory, one connection), **MySQL 8.4**, **MariaDB 11.4** (since 2.23.0) and
**PostgreSQL 16**, plus the twin-port and private-entity-manager replays. Tests that need two connections run only on
a server and are skipped on SQLite; their logic is also simulated on SQLite so the coverage gate sees every branch.

## Rules every write follows

- **Check, then lock, then decide on the locked row.** Input and rights are checked before the transaction. Inside it the
  rows the write depends on are locked (`SELECT … FOR UPDATE`, `LockMode::PESSIMISTIC_WRITE`) and every rule that a
  concurrent writer could break (a version, a state, a cap) is decided again on what was locked.
- **One transaction at the connection's current level** (`TaskApiTransaction`): a savepoint inside a host's transaction
  or a transaction-doctrine write unit. On a failure it rolls back to the entry level, drops the held events, resets a
  manager the failure closed and reloads what it touched.
- **Fixed lock order** (2.21.0, audit SF-M1): columns before tasks; within one kind, ascending id.
- **Events after the outermost commit** (`TaskEventDeferral`); a listener's failure after the commit is logged, not
  reported as a failed write.
- **No blind replay of a business conflict.** A version or revision conflict (`TaskQuestionConflictException`,
  `TASK_409`) is not a lock conflict: a write unit never replays it.

## Writes inside a host's transaction

Under REPEATABLE READ (the default of MySQL and MariaDB), a plain read inside a transaction sees that transaction's
snapshot, while a locking read (`FOR UPDATE`) sees the latest committed row. So after locking a row that turned out to be
newer than the loaded entity, `EntityManager::refresh()` may still give back the **old** values.

Since 2.23.0 (audit TE-M1) the bundle never writes from such a copy:

- `TaskQuestionManager` compares the version it locked with the version the refresh gave; when they differ the write is
  refused: *withdrawn* when the locked state is withdrawn, else `TaskQuestionConflictException` with the locked version.
- `TaskApiTransaction::lockedRevision()` does the same for a task's revision and parent: `TaskApiRefusal` 409 `TASK_409`
  with `current_revision`.

The caller reads again in a new transaction. PostgreSQL (READ COMMITTED) reads the committed row on refresh and goes on.
The bundle's own pages and Task API load outside a transaction, so they meet this only through a host's wrapper.

## Lock order

Every path takes its locks in one fixed order, so two writers on one task wait for each other instead of deadlocking
(audit TE-2: an ask and an answer on the same task deadlocked when the answer locked the question first and the task
only at its flush).

| Path | Order |
|---|---|
| Ask | task, then the task's open questions |
| Answer, supersede | task, question, then the question's answers |
| Withdraw, publish decision | task, question |
| Task API writes with `base_revision` (and bulk updates) | the columns the status enters, then the task rows (ascending id) |
| Delete (task, Task API delete) | task, then the rows its cascade removes |
| Task edit form with `baseDigest` (2.31) | the column the edit enters (if any), then the task row (its revision read under the lock) |
| Custom field value (form, Task API, import; 2.31, 3.3) | the task row, then the value row |
| Custom field definition edit / delete (3.3) | the field row, then its value rows (and a tombstone row on delete) |
| Import: drop-down options of a native field (3.3) | the field row (once per run and field, outside any task lock) |
| Import: creating the board's field of a marker (3.3) | the board row, then a savepoint for the field row |

The question paths take the task's row first in `TaskQuestionManager::lock()` (`lockTask()`), exactly as `ask()` does.
The activity entry and the flush then touch the task again under a lock the transaction already holds.

## Questions on tasks (2.23)

| Write | Locked | Decided under the lock | Loser |
|---|---|---|---|
| Ask | the task's row | open questions < `max_open_per_task` (counted with a locking read, TE-L1) | `TaskQuestionRefusedException` `task_closed` (API `QUESTION_005`) |
| Answer / supersede | the task's row, then the question's row | version (or `open` without one), not withdrawn, answers < 50 (locking read) | conflict (API 409 `CONFLICT_002` + `current_version`), or *withdrawn* (`QUESTION_003`) |
| Withdraw | the task's row, then the question's row | version, not withdrawn | conflict or *withdrawn* |
| Publish decision | the task's row, then the question's row (after the host published, outside the transaction) | version | conflict |

The open-question count is a locking read counted in PHP: PostgreSQL refuses `FOR UPDATE` with an aggregate, and a
locking read sees rows a concurrent ask committed after the snapshot.

## Task edit form (2.31)

The board UI's task form posts every field it shows, so a save over someone else's would revert their changes. Since
2.31.0 the form posts the digest of the content it showed (`baseDigest`, `Support\TaskContentDigest`: title,
description, priority, estimate, dates, tags, colour). `TaskManager::update()`:

- refuses at once when the loaded task's content no longer matches the digest (someone saved while the form was open);
- otherwise locks the column the edit enters (if any), then the task's row, and refuses when the committed revision is
  not the one this request loaded (a save committed between the load and the lock);
- answers `TaskChangedException`: the page flashes "The task changed meanwhile: nothing was saved. Try again." and
  shows the task as it is now. Nothing is written and no history row is recorded.

Custom fields (2.31): the form also posts the values it showed (`customFieldBase`). A field the person did not touch
is not written; one the person changed that someone else changed too refuses the save before anything is written;
`TaskCustomFieldManager` locks the task's row before reading a value, so a change committed between those checks and
the write is skipped and reported ("was not saved: someone changed it meanwhile"), never overwritten.

A move of the card alone does not change the digest: the edit is saved and the task stays in its new column (2.2.1).
A deadlock or lock wait timeout on the task row answers the platform's 409 `CONFLICT_001` (see below). Writes without a
digest (Task API, host ports, host forms without the `base_digest` option) behave as before.

## Task API writes

- Updates with `base_revision` and bulk updates lock the columns they enter, then the task rows (ascending id), and
  compare the locked revision (`TASK_409`).
- Creates (with assignees) and deletes are one transaction.

## Host ports by id (2.29)

`Service\TaskCommands` (the id-based `TaskCommandsInterface`) follows the rules above:

- **Check, then lock.** The user, the resources and the access rule are checked before the write unit; inside it a task
  write locks the column it enters (if any), then the task's row, and decides on the locked row (fixed order).
- **Revision conflicts are 409.** With `$expectedRevision` (`updateTask`, `moveTask`) another revision on the locked row
  is `TaskChangedException` (`currentRevision`): nothing is written. Under REPEATABLE READ a refresh that still gives
  the old revision (an outer host transaction's snapshot) is refused the same way, never written from.
- **Lock conflicts are transient.** A deadlock, lock wait timeout or PostgreSQL `lock_timeout` inside the unit rolls it
  back and throws `Nowo\TransactionContract\TransientStorageFailure` (retry; HTTP 503 + `Retry-After`).
- **No read-modify-write in PHP.** `adjustTotalTime()` is one `UPDATE … SET total_time_seconds = total_time_seconds + ?`
  (then a clamp at 0 in the same unit, the row already locked); concurrent adjustments add up.
- **Host object links are added once.** `addHostObjectLink()` locks the task's row before looking for an existing link
  to the same object, so two concurrent calls add one link.
- **Erasure is batched and idempotent.** `clearTimeEntrySnapshotsForUser()` selects at most 500 detached entries of the
  exact user id per batch (keyset on the id) and clears them by id; a second run changes nothing.
- **Events after the host's commit** (opt-in `events.after_host_commit`): see [Configuration](CONFIGURATION.md#database).

## Lock conflicts and replays

A deadlock or lock wait timeout answers transaction-doctrine's policy: 409 (or 503) `CONFLICT_001` with `Retry-After`
when nothing was committed; `CONFLICT_002` without it when the request already committed part of its work. The commit
ledger sees only DBAL writes on DoctrineBundle connections (SU2-I2). A write unit's replay re-reads everything by id and
writes the principal's user as the manager manages it now (2.22.0); a stored attachment is reused only when its store
committed (SU2-I1).

## Remote sync, imports and the number backfill

| Write | Transaction | Concurrency control | Loser |
|---|---|---|---|
| Remote sync run ([sync.md](sync.md)) | one transaction per page of tasks, at the connection's current level (a savepoint inside a host's transaction); the run row, heartbeat and cursor are separate small writes | the board's run lock: an atomic conditional `UPDATE` of the connection row (`running_run_id`, heartbeat); a lock without heartbeat for 10 minutes is taken over | a second run gets status `busy` and writes nothing |
| Remote sync, files, comments and task details | after the page commit, outside any transaction | the provider's version per task (a version already read is not read again) | a failure marks the tasks not reached for a new read on the next run |
| Dry run | the page is written, then rolled back to the entry level | as a run | — |
| CSV / JSON import | one transaction for the whole import (rows flushed in batches of 200 inside it) | idempotent upsert on (import scope, source id) or the row's fingerprint; the board quota is checked before anything is written | a failure leaves nothing behind |
| Number backfill (`nowo:task-board:numbers-backfill`) | one transaction per batch | numbers reserved with the atomic counter (`TaskNumberReserver`); each row is updated only `WHERE number IS NULL` | a task numbered meanwhile is skipped (a gap, never a duplicate) |

No provider HTTP call, file download or mail runs inside a sync page's transaction or under a row lock: the page is
fetched before the transaction, and files, comments and details are read after the commit. Events are dispatched after
the commit (`TaskEventDeferral`).

## ClickUp OAuth helper (3.1)

CU-1 adds no write: `ClickUpOAuthClient` and the OAuth value objects are stateless, and the token, the `state` and the
connection row live in the host. The races of the flow are the host's: the `state` is single use (removed from the
session before it is checked, so a replayed or doubled callback fails **with a locking session handler** such as
native files or PDO with locking; with a non-locking handler (Redis, Memcached) two parallel callbacks can both read
it, and then ClickUp's single-use code makes the second exchange fail), and reconnecting replaces the token of the same
connection row (one per tenant, ClickUp user and app), so a concurrent run slice either uses the old token or resolves
the new one through `credentialFor()`; nothing is cached in the bundle.

## The application's entity manager

The sync, the import and the backfill write through the bundle's private manager (since 3.0.0 the only mode), which
they clear after each sync page, after a dry-run rollback and after each backfill batch to keep the memory of a long run
flat (`BundleEntityDetacher`, REQ-RUNTIME-003 rule 2): the application's manager is never flushed, cleared or reset.
Up to 2.x without `database.private_entity_manager` they wrote through the application's manager and detached only
the TaskBoard rows no application entity referenced; that mode was removed in 3.0.

A TaskBoard row the application loaded through its own manager is read again by id at the entry of every public
write service (`TaskBoardEntityManager::attach()`, audits ZAS-1 / ZAS-V1 / ZAS-W1 / ZAS-X1): TaskBoard writes and
locks its own copy, never the application's instance, so the application's next flush cannot write a stale copy over
TaskBoard's change, and an audit event or a returned count always describes a write that was really made. A row that
is gone or not flushed yet is refused before anything is written.

A manager that a failed flush closed is replaced (`RecoveringFlusher`), as before. The import detaches its own batch
classes only (1.22.2).

## Residual risks

Accepted. None of them leaks data or bypasses a permission.

- **TaskBoard rows held across a batch.** After a sync page, a dry-run rollback or a backfill batch the private manager
  is cleared: a TaskBoard row the application holds in a variable is detached. Load it again by id before changing it.
  (Up to 2.x without the private manager, a shared manager also flushed the application's pending changes; that mode
  was removed in 3.0.)
- **Local edit during a sync page.** A sync page does not lock the task rows it updates. A local edit of the same field
  committed between the page's read and its commit is overwritten by the provider's value (last writer wins on that
  field only; other fields keep the local edit). The window is one page.
- **Long imports hold their locks.** An import is one transaction (all or nothing), so a large file keeps its row locks
  until the end. `import.max_rows` bounds it.
- **Stale run lock.** A run that stops without releasing its lock blocks the board's sync for up to 10 minutes; a run
  that lives longer without a heartbeat can be overtaken by a second one (pages stay idempotent: items are keyed by
  connection and external id).

## Tests

| Test | What | Databases |
|---|---|---|
| `tests/Functional/Question/TaskQuestionConcurrencyTest.php` | two first answers, a stale version and an answer after a withdraw, each inside an outer transaction (two connections); the open-per-task cap against a concurrent ask; `lockedRevision()` against a stale snapshot; an ask in another process against an answer (lock order, TE-2); the stale refresh simulated | MySQL, MariaDB, PostgreSQL (two connections); SQLite (simulated) |
| `tests/Functional/Question/TaskQuestionManagerTest.php` | the racing first answer and stale versions on one connection | all |
| `tests/Functional/Api/ApiTaskQuestionReplayTest.php`, `ApiWriteUnitReplayTest.php` | write-unit replays after a deadlock | all |
| `tests/Functional/Api/ApiLockOrderTest.php`, `ApiLockConflictTest.php` | fixed lock order, conflict answers | all |
| `tests/Functional/Question/TaskQuestionWorkerTest.php` | one kept-alive kernel, a forced failure, flat memory | all |
| `tests/Functional/Sync/SyncPrivateEntityManagerHostTest.php` | a sync and a dry run with the private manager leave the application's manager untouched | all |
| `tests/Functional/HostPorts/HostPortsConcurrencyTest.php` (group `host-ports-db`) | two processes adjusting one task's total (no lost adjustment); a task row another connection holds (lock wait timeout: transient failure, nothing written, the retry succeeds); a revision another connection committed (409, nothing written) | MySQL, MariaDB, PostgreSQL (two connections / processes; skipped on SQLite) |
| `tests/Functional/HostPorts/HostPortsTest.php`, `EventsAfterHostCommitTest.php`, `HostPortsWorkerTest.php` | conflicts on one connection, events held until the host's commit and dropped on rollback, a kept-alive worker | all |
| `tests/Functional/Sync/SyncServiceTest.php`, `Number/TaskNumberConcurrencyTest.php` | the run lock (`busy`), concurrent number reservations | all; two processes on a server |

Run them on a server with `make test-with-db` (MySQL and PostgreSQL) or with `TASK_BOARD_TEST_DATABASE_URL` set to a
MariaDB URL (`mysql://…?serverVersion=11.4.0-MariaDB`).

## ClickUp import plans (3.2)

REQ-RUNTIME-003 for the guided import (design §19):

| Race | Guard |
| --- | --- |
| Two plans of one tenant and workspace started at once | `lock_key` (SHA-256 of tenant, provider, workspace) is set in the same `UPDATE … WHERE version = ? AND lock_key IS NULL` that checks the version; its unique key refuses the second (`workspace_busy`) |
| Two managers editing a draft | every step is `UPDATE … SET version = version + 1 WHERE version = ? AND status = 'draft'`; the loser gets `stale` |
| Two workers on one plan (redelivery, inline poll + worker) | `claim()`: `UPDATE … SET status = 'running', heartbeat_at = now, run_token = <new> WHERE status IN (queued, paused, rate_limited) OR (running AND heartbeat older than 10 min)`; the loser runs nothing. A slice lasts at most 300 s and beats after each board |
| A slice taken over (or a plan cancelled) while it still runs | fencing: heartbeat, report, hold and end are `UPDATE … WHERE run_token = <its token> AND status = 'running'`; the stale slice stops and never frees `lock_key` |
| Cancel or Resume against a claim | `cancel()` reads the status `FOR UPDATE` and decides in the same transaction: a waiting plan ends (`cancelled`, `lock_key` freed), a running one gets `cancel_requested`, anything else is left alone (audit ZBYv-L2); `makeDue()` is a compare-and-set on the held statuses |
| A cancel asked while the slice is about to pause | the hold is `… AND cancel_requested = false`: refused, the slice ends the plan `cancelled` instead (one event, from the slice) |
| MySQL / MariaDB report changed, not matched, rows | only writes that can leave the row unchanged (heartbeat, report, `cancel_requested`, *Resume*) re-check their guard on 0 rows; status changes never do |
| A wizard step that fails half-way | the version compare-and-set, the item rewrite and the flush are one transaction |
| Two starts that each pass the board quota | the quota is asked again per board right before it is created |
| A crash between "board created" and "item updated" | the board and its item are written in one transaction; a resumed start creates only the boards still missing |
| A crash after the link, before the item recorded it | the next slice adopts the board's connection when it follows the item's source (no second link) |
| A plan item and a manual Refresh of the same board | the board's run lock: the item gets `busy` and the plan retries 30 s later |
| People mapped while the plan runs | the existing re-association locks per board and `PeopleMappingService::resume()` after each board run |
| Token replaced while running | the next slice resolves it through `credentialFor()`; nothing is cached |
| Shared request budget | read-modify-write per one-minute window in a PSR-6 pool: two processes may both take the last request; the 10 % margin and the 429 handling cover it |

Tests: `ImportPlanRunnerTest` (lock, claim, adoption, busy board, cancel), `ImportPlanFencingTest` (takeover, cancel
and resume against a claim, step transaction, and with a second database connection on MySQL / MariaDB / PostgreSQL:
claim, workspace lock, cancel, a pause refused after the cancel, a paused plan cancelled), `ImportPlanFlowTest` (stale and
forged steps).

## Native custom fields (3.3)

| Race | Guard |
| --- | --- |
| Two people editing one field's definition (name, options) | `update()` / `delete()` lock the field's row (`delete()` of an imported field locks the board's row first, as `defineFromSource()`: board, then field, audit ZCFv2-I2) and read its `version` in that locking read (a current read, audit ZCFv-L2), then read the row again and raise `version`; the page's forms send the version they showed and an older one is refused (`changed`, nothing written). Inside a caller's REPEATABLE READ transaction whose snapshot is older than the locked row, the change is refused too (the copy would write over the newer row) |
| An import adding drop-down options while someone edits the field | `adoptSourceOptions()` takes the same row lock and re-reads the options; the person's edit then fails its version check (reload and save again) |
| Two runs creating the board's field of one ClickUp field | `defineFromSource()` locks the board's row, then looks for the marker again with a locking (current) read that also hydrates the row (audits ZCFv-I3, ZCFv2-I1: also inside a caller's older snapshot): the second writer waits and takes the first's field. The insert runs in a named savepoint of its own (also on DBAL 3 without `use_savepoints`, audit ZCFv-I1), so a writer that skipped the lock and won the unique key (`board_id`, `source_ref`) only undoes that savepoint; its field is read again and the sync page goes on (audit ZCF-L3) |
| *Import it again* while a sync of the board runs | `clearTombstone()` locks the board's connection row (`SELECT … FOR UPDATE`) and refuses (`sync_running`, nothing written) while a sync holds the run lock with a live heartbeat (audit ZCFv3-L1: that run caches the field as deleted and would write over the reset). Otherwise, in the same transaction, it resets the board's sync items, then the connection's watermark, and removes the tombstone (connection, items, tombstone). A sync that starts meanwhile takes its run lock with an UPDATE of that row, so it waits for the commit and then reads every task. A run that deferred tasks (`native_field_changed`) keeps the previous watermark (audit ZCFv2-L1) |
| A person editing a task's value while the import writes it | the import first reads and decides every value of the task, then writes each through `setFieldIfUnchanged()`: the task's row is locked and the value read again; when it is no longer the one the import read, nothing is written (the transaction commits empty, so the sync page's rows stay managed) and the person's value wins (a conflict note) |

The import never holds a task row while it locks a field or board row: definitions are resolved before the values are
written, so the orders above do not cross.

Tests: `CustomFieldDefinitionTest` (version refusals, marker reuse, option map, a definition saved by a second
connection first, a second field of one marker refused by the unique key), `SyncNativeFieldsAuditTest` (audit ZCF:
**two processes** on MySQL / MariaDB / PostgreSQL — another process holds the board's row with its field inserted while
this one waits and then takes it; another process holds the task's row with a person's value while the import waits and
then keeps it — and on every database the same interleavings through the restructure policy, asked between the reads and
the writes, plus a duplicate marker inside the write, read again, during a sync page that still succeeds),
`SyncNativeFieldsTest` (the re-import rule), `CustomFieldPagesTest` (stale forms), and
`CustomFieldDefinitionTest::testAnOuterTransactionsOldSnapshotNeverWritesOverANewerDefinition` (audit ZCFv-L2: an outer
REPEATABLE READ transaction reads first, a second connection commits an option edit, then an edit, an option merge and a
version-less edit from the old snapshot are refused on MySQL, MariaDB and PostgreSQL).

Known limit (audit ZBYv-I1, planned for 3.2.x): item rows are still written through the ORM without the run token, so a
slice that was taken over may write its current item's status once more before its next fenced write stops it. The
board's run lock makes the live slice's item `busy` meanwhile, so no task is imported twice; at worst an item shows a
stale status until the live slice reaches it.

## Client API v1 (3.4)

Every write of `/task-board/v1` ([CLIENT-API](CLIENT-API.md)) follows the Task API's rules:

- **One short transaction per change** (`TaskApiTransaction`): rollback to the entry level, the connection closed when
  that rollback fails (a MySQL / MariaDB deadlock ended the transaction), the original exception rethrown, the task
  events held until the commit and dropped on a rollback, the touched tasks reloaded.
- **Lock order**: the column a move enters (`OrderedRowLockInterface::lockColumns()`), then the task's row
  (`lockedRevision()`, `SELECT … FOR UPDATE`), then `TaskManager::move()`'s own column scan (same order). `If-Match` is
  compared on the locked row (database-incremented revision): a stale one is `409 conflict {currentRevision}` and
  nothing is written. A reorder inside one column is last-writer-wins by design (`If-Match: *`, no revision bump).
- **Checklist items** compare the item's own revision, after locking the column the tick enters (done, or back to the
  first column: column before row, audit ZCS-L3); members compare the task's.
- **The change is built from the row as locked** (audit ZCS-I1): a field the client did not send keeps the committed value.
- **No I/O under the locks**: the host's `TaskClientSourceLinkInterface` is asked before the transaction; the audit
  event and the answer are built after it.
- **Idempotency**: creates require `Idempotency-Key` (the Task API's store: claim, 24 h; a replay re-checks reach; a lock
  conflict after a partial commit keeps the `409 partial` answer so the key never writes twice).
- Lock conflicts anywhere in the chain answer `409 unavailable` (`CONFLICT_001`, `Retry-After`) or `409 partial`
  (`CONFLICT_002`).
- Tests: `ClientApiRaceTest` (two connections: a web write between the device's read and write, two devices moving the
  same task, a stale checklist tick), on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in CI and locally.

## Forget this person (3.4)

`TaskSyncPeopleDirectoryService::anonymise()` (the People page action and any host caller) is one short transaction of
its own (a savepoint inside the caller's): the directory's row is locked first (its counter numbers the former people),
then the person's row; both are read as committed; the person is anonymised only when no concurrent call did it first
(idempotent: a second call changes nothing and dispatches nothing); the audit event (`SYNC_PERSON_ANONYMISED`, ids only)
is dispatched after this call's commit (inside a caller's transaction: after this call's savepoint, before the
caller's commit, which may still roll back; audit ZCS-I8). A failure rolls back to the entry level (`TransactionRollback`), resets a closed manager
and rethrows. The erasure path (`forget()`, inside `detachUser()`) keeps its caller's transaction. Test:
`ForgetPersonTest::testTheCounterIsReadUnderTheLockAndAPersonForgottenMeanwhileIsLeftAlone`.

