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
- [Task API writes](#task-api-writes)
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

## Task API writes

- Updates with `base_revision` and bulk updates lock the columns they enter, then the task rows (ascending id), and
  compare the locked revision (`TASK_409`).
- Creates (with assignees) and deletes are one transaction.

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

With `database.private_entity_manager: true` the sync, the import and the backfill write through the bundle's private
manager, which they may clear: the application's manager is never flushed, cleared or reset.

Without it (the 2.x default, deprecated) they write through `database.entity_manager`, which is the application's.
Since 2.27.0 (REQ-RUNTIME-003 rule 2) a long run keeps its memory flat with `BundleEntityDetacher`, after each sync
page, after a dry-run rollback and after each backfill batch, and never clears, closes or resets the manager:

- The application's entities stay managed, with their pending changes. This includes the application entities the sync
  loaded itself, such as assignee users.
- A TaskBoard row that a managed application entity references stays managed: a to-one value, or an element of an
  initialized collection. So does every TaskBoard row it reaches through initialized TaskBoard associations: a kept
  task keeps its board and its column. The sync then finds the same instances again.
- Every other TaskBoard row is detached. If the rows to keep cannot be worked out, nothing is detached for that batch
  and a warning is logged.
- After a dry-run rollback, the kept TaskBoard rows are read again from the database. Application rows that the
  rolled-back page inserted (for example from an application `onFlush` listener) are detached, and the ones it updated
  are read again.

A manager that a failed flush closed is replaced (`RecoveringFlusher`), as before. The import detaches its own batch
classes only (1.22.2).

## Residual risks

Accepted for 2.x. None of them leaks data or bypasses a permission.

- **Shared manager flushes the application's changes.** Without the private manager, a TaskBoard flush (sync, import,
  any write) also writes what the application left pending in that manager. This is the deprecated 2.14 behaviour;
  the private manager becomes the only mode in 3.0 (REQ-ARCH-001 rule 6). A dry run commits the application's pending
  changes too, with the run row, before its pages are rolled back.
- **TaskBoard rows the application holds only in a variable.** After a sync page or a backfill batch, a TaskBoard row
  that no managed application entity references is detached, even if the application holds it in a variable. Load it
  again by id before changing it.
- **The application's pending changes to TaskBoard rows.** "Pending changes are kept" applies to the application's own
  entities only. The backfill writes with plain SQL and never flushes, so a change the application left unflushed on a
  detached TaskBoard row, or a new TaskBoard row it persisted without flushing, is dropped, as it was in 2.26.1. A task
  that stays managed (an application entity references it) keeps the number it had in memory: load it again to read the
  new number.
- **More rows stay in memory.** Kept TaskBoard rows (and everything they reach through initialized collections) and
  the application entities the sync loaded stay in the manager for the whole run.
- **Dry run: application collections.** After a dry-run rollback, the rows the page wrote are forgotten in the manager
  TaskBoard writes through. An application entity that holds one of them in a collection it has already loaded (and that
  the page did not update) may still list it until it is loaded again.
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
| `tests/Functional/Sync/SyncHostEntityManagerTest.php`, `Number/BackfillHostEntityManagerTest.php` | a sync, a dry run and a backfill batch on the application's manager keep its entities managed and its pending changes | all |
| `tests/Functional/Sync/SyncHostReferencesTest.php`, `Number/BackfillHostEntityManagerTest.php` | application entities that reference a board or a task (also with cascade persist) across a one-page and a two-page sync, a dry run and a backfill batch; application rows an `onFlush` listener wrote in a rolled-back dry-run page | SQLite, MySQL, MariaDB, PostgreSQL |
| `tests/Functional/Sync/SyncPrivateEntityManagerHostTest.php` | a sync and a dry run with the private manager leave the application's manager untouched | all |
| `tests/Functional/Sync/SyncServiceTest.php`, `Number/TaskNumberConcurrencyTest.php` | the run lock (`busy`), concurrent number reservations | all; two processes on a server |

Run them on a server with `make test-with-db` (MySQL and PostgreSQL) or with `TASK_BOARD_TEST_DATABASE_URL` set to a
MariaDB URL (`mysql://…?serverVersion=11.4.0-MariaDB`).
