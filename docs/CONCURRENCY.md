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
