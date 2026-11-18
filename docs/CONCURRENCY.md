# Concurrency (REQ-RUNTIME-003)

How the bundle's writes behave when two writers meet the same rows: what is locked, in which order, what a loser gets,
and which tests prove it on which databases. Worker mode (kernel not reset) is covered in
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md); the Task API's answers in [API.md](API.md).

## Table of contents

- [Databases tested](#databases-tested)
- [Rules every write follows](#rules-every-write-follows)
- [Writes inside a host's transaction](#writes-inside-a-hosts-transaction)
- [Questions on tasks (2.23)](#questions-on-tasks-223)
- [Task API writes](#task-api-writes)
- [Lock conflicts and replays](#lock-conflicts-and-replays)
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

## Questions on tasks (2.23)

| Write | Locked | Decided under the lock | Loser |
|---|---|---|---|
| Ask | the task's row | open questions < `max_open_per_task` (counted with a locking read, TE-L1) | `TaskQuestionRefusedException` `task_closed` (API `QUESTION_005`) |
| Answer / supersede | the question's row | version (or `open` without one), not withdrawn, answers < 50 (locking read) | conflict (API 409 `CONFLICT_002` + `current_version`), or *withdrawn* (`QUESTION_003`) |
| Withdraw | the question's row | version, not withdrawn | conflict or *withdrawn* |
| Publish decision | the question's row (after the host published, outside the transaction) | version | conflict |

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

## Tests

| Test | What | Databases |
|---|---|---|
| `tests/Functional/Question/TaskQuestionConcurrencyTest.php` | two first answers, a stale version and an answer after a withdraw, each inside an outer transaction (two connections); the open-per-task cap against a concurrent ask; `lockedRevision()` against a stale snapshot; the stale refresh simulated | MySQL, MariaDB, PostgreSQL (two connections); SQLite (simulated) |
| `tests/Functional/Question/TaskQuestionManagerTest.php` | the racing first answer and stale versions on one connection | all |
| `tests/Functional/Api/ApiTaskQuestionReplayTest.php`, `ApiWriteUnitReplayTest.php` | write-unit replays after a deadlock | all |
| `tests/Functional/Api/ApiLockOrderTest.php`, `ApiLockConflictTest.php` | fixed lock order, conflict answers | all |
| `tests/Functional/Question/TaskQuestionWorkerTest.php` | one kept-alive kernel, a forced failure, flat memory | all |

Run them on a server with `make test-with-db` (MySQL and PostgreSQL) or with `TASK_BOARD_TEST_DATABASE_URL` set to a
MariaDB URL (`mysql://…?serverVersion=11.4.0-MariaDB`).
