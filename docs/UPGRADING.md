# Upgrading

## Table of contents

- [To 1.5.1](#to-151)
- [From 1.5.5 to 1.5.6](#from-155-to-156)
- [Unreleased](#unreleased)
- [To 1.25.0](#to-1250)
  - [Behaviour changes for sync hosts](#behaviour-changes-for-sync-hosts)
  - [Import access decision (every host)](#import-access-decision-every-host)
  - [Schema change for every host: task creator and comment author (audit FP-M1)](#schema-change-for-every-host-task-creator-and-comment-author-audit-fp-m1)
  - [Schema change with `sync.enabled: true` only](#schema-change-with-syncenabled-true-only)
  - [After the update (sync hosts)](#after-the-update-sync-hosts)
- [To 1.24.0](#to-1240)
- [To 1.23.2](#to-1232)
  - [Time entries survive their task](#time-entries-survive-their-task)
- [To 1.23.1](#to-1231)
- [To 1.23.0](#to-1230)
  - [Schema change for every host: archived tasks](#schema-change-for-every-host-archived-tasks)
  - [Schema change with `sync.enabled: true` only: the sync tables](#schema-change-with-syncenabled-true-only-the-sync-tables)
  - [Provider sync (opt-in)](#provider-sync-opt-in)
  - [Known unconfirmed ClickUp behaviour](#known-unconfirmed-clickup-behaviour)
  - [Task member pictures (opt-in)](#task-member-pictures-opt-in)
- [To 1.22.2](#to-1222)
- [To 1.22.1](#to-1221)
- [To 1.22.0](#to-1220)
- [To 1.21.0](#to-1210)
- [To 1.20.1](#to-1201)
- [To 1.20.0](#to-1200)
- [To 1.19.1](#to-1191)
- [To 1.19.0](#to-1190)
- [To 1.18.1](#to-1181)
- [To 1.18.0](#to-1180)
- [To 1.17.1](#to-1171)
- [To 1.17.0](#to-1170)
- [To 1.16.1](#to-1161)
- [To 1.16.0](#to-1160)
- [To 1.15.0](#to-1150)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.0](#to-190)
- [To 1.8.4](#to-184)
- [To 1.8.3](#to-183)
- [To 1.8.2](#to-182)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.2](#to-172)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.8](#to-158)
- [To 1.5.7](#to-157)
- [To 1.5.4](#to-154)
- [To 1.5.3](#to-153)
- [To 1.5.2](#to-152)
- [To 1.5.0](#to-150)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [1.4.0 (2026-08-03)](#140-2026-08-03)
  - [Install / update](#install--update)
  - [REQ-UI-002 allow_unauthenticated](#req-ui-002-allow_unauthenticated)
  - [Breaking changes](#breaking-changes)
- [1.3.1 (2026-07-30)](#131-2026-07-30)
  - [CSRF on kanban / bare POSTs (REQ-SEC-005)](#csrf-on-kanban--bare-posts-req-sec-005)
- [1.3.0 (2026-07-30)](#130-2026-07-30)
  - [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
- [1.2.4 (2026-07-29)](#124-2026-07-29)
  - [Notes](#notes)
- [1.2.3 (2026-07-16)](#123-2026-07-16)
  - [Notes](#notes-1)
- [1.2.2 (2026-07-13)](#122-2026-07-13)
  - [Notes](#notes-2)
- [1.2.1 (2026-07-08)](#121-2026-07-08)
  - [Notes](#notes-3)
- [1.2.0 (2026-07-07)](#120-2026-07-07)
  - [New: task import](#new-task-import)
- [1.1.1 (2026-07-07)](#111-2026-07-07)
  - [Notes](#notes-4)
- [1.1.0 (2026-07-06)](#110-2026-07-06)
  - [If you use time tracking](#if-you-use-time-tracking)
  - [If you only use TaskBoard](#if-you-only-use-taskboard)
  - [Notes](#notes-5)
- [1.0.1 (2026-07-06)](#101-2026-07-06)
  - [Notes](#notes-6)
- [1.0.0 (2026-07-06)](#100-2026-07-06)
  - [Requirements](#requirements)
  - [Install](#install)
- [Future 1.x releases](#future-1x-releases)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)

## To 1.5.1

From **1.5.0** — YAML services_timetrack indentation fix only. No host migration.

```bash
composer update nowo-tech/task-board-bundle
```

This document describes how to upgrade between versions of TaskBoard Bundle.

## From 1.5.5 to 1.5.6

No breaking changes and no host migration. Behaviour changes to be aware of:

- Creating a board whose slug already exists now stores it as `<slug>-2`, `<slug>-3`, … instead of failing with a unique-constraint error.
- Assigning the same user twice with the same role returns the existing `TaskMember` and does not write a second change-history entry.
- Code that instantiates `TaskAccessGuard`, `TaskImportOrchestrator`, `TimeSpentAggregatorListener` or the flushing `DoctrineOrm*Repository` classes manually keeps working: the new `?ManagerRegistry` argument is optional (without it, a closed EntityManager is not reset and access/timer paths do not refresh entities).

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

Hosting FrankenPHP with **kernel not reset** between requests: see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) and the host checklist in [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) (clear the Doctrine identity map between requests, or keep `services_resetter`).

## Unreleased

The next release is **1.25.0**: see [To 1.25.0](#to-1250) (ClickUp full import: people, creator, watchers, checklists,
tracked time through a host sink, status types).

## To 1.25.0

From **1.24.0**: `composer update nowo-tech/task-board-bundle`. **Every host** gets one schema change: the task creator
and the comment author survive the deletion of their account ([below](#schema-change-for-every-host-task-creator-and-comment-author-audit-fp-m1)).
Hosts **without** `sync.enabled` get no other schema change; the new `import_tasks` access decision below also applies
to them.

### Behaviour changes for sync hosts

1. **Original creation date.** A task the sync creates gets the provider's creation date as `Task::createdAt`
   (accepted between 2000-01-01 and now + 5 min, else now). Tasks written by 1.23 / 1.24 get it once, with their
   creator, on their next fetch (back-fill, no conflict note).
2. **No author prefix on new comments.** New imported comments are written without the `**name** (ClickUp):` line; the
   templates render *name (ClickUp)* from the person reference, and the comment keeps the ClickUp date. Run the
   one-off legacy migrator for comments written before (below).
3. **Verified e-mails only (`sync.people.require_verified_email: true`, default).** An e-mail match is applied
   automatically only when the user is verified: the host resolver says so, and `DefaultSyncPersonResolver` reads
   `isVerified()` when the user class has it. **A user class without `isVerified()` now gets *suggested* matches**,
   which need a confirmation (`applyMapping()` with a `PersonMappingDecision::CONFIRM` decision). Set the option to `false` for 1.23 parity.
4. **Projection rule.** A mapped user who cannot view the board (`canViewBoard()`) is no longer written as creator,
   assignee, watcher or comment author: the run's actor is written plus the person reference, and the person is listed
   as *Also assigned in ClickUp*. A mapping never grants access.

### Import access decision (every host)

File imports (into a board or under a task) and `canManageBoardConnection()` now also dispatch `BOARD_ACCESS_CHECK`
with the action `BoardAccessCheckEvent::ACTION_IMPORT_TASKS` (`import_tasks`). It is **granted by default**, so nothing
changes unless a listener denies it. To make importing a narrower power (e.g. Beacon's `project.tasks.import`, Full /
Owner only, on a general board only its owner), deny that action in your listener:

```php
if ($event->getAction() === BoardAccessCheckEvent::ACTION_IMPORT_TASKS && !$this->canImport($event->getSubject(), $event->getBoard())) {
    $event->deny();
}
```

A listener that denies every action without reading `getAction()` already denied imports; one that grants every
action leaves imports granted, as today. Refused file imports answer 403; the connection pages answer 404 as before.

### Schema change for every host: task creator and comment author (audit FP-M1)

`task_board_tasks.creator_id` and `task_board_task_comments.author_id` become **nullable with `ON DELETE SET NULL`**
(they were `NOT NULL … ON DELETE CASCADE`), and each row keeps the name it was written with in `creator_name` /
`author_name` (`VARCHAR(180)`, `UserLabel::of()` of the user). **Deleting a user no longer deletes the tasks they
created (with their subtasks) or their comments**: the reference becomes null and the UI shows the kept name.
`Task::getCreator()` and `TaskComment::getAuthor()` now return `?object`; new `getCreatorName()` / `getAuthorName()`.
Code that reads the creator or author must handle null.

**Boards and task documents too** (audit FP-L7): `task_board_boards.creator_id` and
`task_board_task_documents.creator_id` get the same change (nullable, `ON DELETE SET NULL`, `creator_name`). Deleting
the account of a board's creator no longer deletes the board with everyone's tasks. `TaskBoard::getCreator()` and
`TaskDocument::getCreator()` return `?object`; new `getCreatorName()`. A board without creator is managed by
`security.manage_roles` holders and the `BOARD_ACCESS_CHECK` listeners (the creator default no longer applies).

Run `doctrine:migrations:diff` (or `schema:update --dump-sql`); it must propose only the statements below. Replace
`<user_table>` with the table of `user_class`, add `table_prefix` to the table names when you set one, and keep your
user id type for `creator_id` / `author_id`.

MySQL / MariaDB:

```sql
ALTER TABLE task_board_tasks DROP FOREIGN KEY FK_B4902F8E61220EA6;
ALTER TABLE task_board_tasks ADD creator_name VARCHAR(180) DEFAULT NULL, CHANGE creator_id creator_id INT DEFAULT NULL;
ALTER TABLE task_board_tasks ADD CONSTRAINT FK_B4902F8E61220EA6 FOREIGN KEY (creator_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
ALTER TABLE task_board_task_comments DROP FOREIGN KEY FK_BC3E8197F675F31B;
ALTER TABLE task_board_task_comments ADD author_name VARCHAR(180) DEFAULT NULL, CHANGE author_id author_id INT DEFAULT NULL;
ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197F675F31B FOREIGN KEY (author_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
ALTER TABLE task_board_boards DROP FOREIGN KEY FK_97612D9961220EA6;
ALTER TABLE task_board_boards ADD creator_name VARCHAR(180) DEFAULT NULL, CHANGE creator_id creator_id INT DEFAULT NULL;
ALTER TABLE task_board_boards ADD CONSTRAINT FK_97612D9961220EA6 FOREIGN KEY (creator_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
ALTER TABLE task_board_task_documents DROP FOREIGN KEY FK_17831DAE61220EA6;
ALTER TABLE task_board_task_documents ADD creator_name VARCHAR(180) DEFAULT NULL, CHANGE creator_id creator_id INT DEFAULT NULL;
ALTER TABLE task_board_task_documents ADD CONSTRAINT FK_17831DAE61220EA6 FOREIGN KEY (creator_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
```

PostgreSQL:

```sql
ALTER TABLE task_board_tasks ADD creator_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_tasks ALTER creator_id DROP NOT NULL;
ALTER TABLE task_board_tasks DROP CONSTRAINT FK_B4902F8E61220EA6;
ALTER TABLE task_board_tasks ADD CONSTRAINT FK_B4902F8E61220EA6 FOREIGN KEY (creator_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE task_board_task_comments ADD author_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_task_comments ALTER author_id DROP NOT NULL;
ALTER TABLE task_board_task_comments DROP CONSTRAINT FK_BC3E8197F675F31B;
ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197F675F31B FOREIGN KEY (author_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE task_board_boards ADD creator_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_boards ALTER creator_id DROP NOT NULL;
ALTER TABLE task_board_boards DROP CONSTRAINT FK_97612D9961220EA6;
ALTER TABLE task_board_boards ADD CONSTRAINT FK_97612D9961220EA6 FOREIGN KEY (creator_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE task_board_task_documents ADD creator_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_task_documents ALTER creator_id DROP NOT NULL;
ALTER TABLE task_board_task_documents DROP CONSTRAINT FK_17831DAE61220EA6;
ALTER TABLE task_board_task_documents ADD CONSTRAINT FK_17831DAE61220EA6 FOREIGN KEY (creator_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
```

SQLite: add the four columns, then let Doctrine rebuild the four tables for the new nullability and foreign key
(SQLite cannot alter a column or a constraint; `doctrine:migrations:diff` generates the copy / drop / rename sequence).
**Back up the database first, and run the rebuild with foreign keys off, outside a transaction** (audit FP-L6): with
`PRAGMA foreign_keys = ON` (e.g. DBAL's `EnableForeignKeys` middleware) the `DROP TABLE` of the rebuild fires the
foreign-key actions and **deletes every comment, member and dependency of the tasks and detaches all their time**. A
`PRAGMA` inside a transaction is ignored, so make that migration non-transactional (`isTransactional(): false`):

```sql
PRAGMA foreign_keys = OFF;
ALTER TABLE task_board_tasks ADD COLUMN creator_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_task_comments ADD COLUMN author_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_boards ADD COLUMN creator_name VARCHAR(180) DEFAULT NULL;
ALTER TABLE task_board_task_documents ADD COLUMN creator_name VARCHAR(180) DEFAULT NULL;
-- then the table rebuild generated by doctrine:migrations:diff (creator_id / author_id NULL, ON DELETE SET NULL)
PRAGMA foreign_keys = ON;
PRAGMA foreign_key_check; -- must return no row
```

The same release adds an index for due-date scans of open tasks (KIT-74), on every platform:

```sql
CREATE INDEX task_board_tasks_board_open_due_idx ON task_board_tasks (board_id, completed_at, due_at);
```

Optional back-fill of the names of existing rows (MySQL syntax; use your user table's display column).
`detachUser()` fills a missing name itself before it detaches a user:

```sql
UPDATE task_board_tasks t JOIN <user_table> u ON u.id = t.creator_id SET t.creator_name = u.email WHERE t.creator_name IS NULL;
UPDATE task_board_task_comments c JOIN <user_table> u ON u.id = c.author_id SET c.author_name = u.email WHERE c.author_name IS NULL;
UPDATE task_board_boards b JOIN <user_table> u ON u.id = b.creator_id SET b.creator_name = u.email WHERE b.creator_name IS NULL;
UPDATE task_board_task_documents d JOIN <user_table> u ON u.id = d.creator_id SET d.creator_name = u.email WHERE d.creator_name IS NULL;
```

### Schema change with `sync.enabled: true` only

Run `php bin/console doctrine:migrations:diff` and review it. The diff must contain exactly the statements below
(MySQL 8 / MariaDB, without `table_prefix`; `<users>` / `<user pk>` as in [To 1.23.0](#schema-change-with-syncenabled-true-only-the-sync-tables)).
No data is rewritten at migration time: existing items get `backfill_level = 0` and `item_kind = 'task'`, existing
connections `directory_id = NULL` (the next run attaches a directory) and `source_type = 'list'`.

Reference DDL (MySQL 8 / MariaDB, without `table_prefix`; `<user pk>` is your user key type):

```sql
CREATE TABLE task_board_sync_directories (
  id VARCHAR(36) NOT NULL, provider VARCHAR(32) NOT NULL, scope_key VARCHAR(64) NOT NULL,
  workspace_id VARCHAR(64) NOT NULL, reviewed_at DATETIME DEFAULT NULL, reviewed_by_id <user pk> DEFAULT NULL,
  anonymous_counter INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL,
  UNIQUE INDEX uniq_task_board_sync_directories (provider, scope_key, workspace_id), PRIMARY KEY (id)
);
CREATE TABLE task_board_sync_people (
  id VARCHAR(36) NOT NULL, directory_id VARCHAR(36) NOT NULL, provider_user_id VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL, email VARCHAR(254) DEFAULT NULL, email_normalized VARCHAR(254) DEFAULT NULL,
  remote_role VARCHAR(16) DEFAULT NULL, mapping VARCHAR(16) NOT NULL,
  mapped_user_id <user pk> DEFAULT NULL, suggested_user_id <user pk> DEFAULT NULL, mapped_by_id <user pk> DEFAULT NULL,
  mapped_at DATETIME DEFAULT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL,
  email_changed_at DATETIME DEFAULT NULL, anonymised_at DATETIME DEFAULT NULL, seen_kinds SMALLINT DEFAULT 0 NOT NULL,
  reassign_id VARCHAR(36) DEFAULT NULL, reassign_to_user_id <user pk> DEFAULT NULL, reassign_external TINYINT(1) DEFAULT 0 NOT NULL,
  UNIQUE INDEX uniq_task_board_sync_people_dir_remote (directory_id, provider_user_id),
  INDEX idx_task_board_sync_people_dir_mapping (directory_id, mapping),
  INDEX idx_task_board_sync_people_mapped (mapped_user_id),
  INDEX idx_task_board_sync_people_dir_email (directory_id, email_normalized), PRIMARY KEY (id)
);
CREATE TABLE task_board_sync_members (
  id VARCHAR(36) NOT NULL, item_id VARCHAR(36) NOT NULL, task_member_id VARCHAR(36) NOT NULL,
  person_id VARCHAR(36) NOT NULL, member_role VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL,
  UNIQUE INDEX uniq_task_board_sync_members_member (task_member_id),
  INDEX idx_task_board_sync_members_person (person_id), PRIMARY KEY (id)
);
ALTER TABLE task_board_connections ADD directory_id VARCHAR(36) DEFAULT NULL,
  ADD source_type VARCHAR(8) DEFAULT 'list' NOT NULL, ADD source_id VARCHAR(128) DEFAULT NULL,
  ADD status_column_map JSON DEFAULT NULL;
ALTER TABLE task_board_sync_items
  ADD creator_person_id VARCHAR(36) DEFAULT NULL, ADD unmatched_people JSON DEFAULT NULL,
  ADD backfill_level SMALLINT DEFAULT 0 NOT NULL, ADD item_kind VARCHAR(16) DEFAULT 'task' NOT NULL;
ALTER TABLE task_board_sync_comments ADD author_person_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE task_board_sync_runs ADD time_mode VARCHAR(16) DEFAULT NULL;
-- FKs: people.directory_id → directories ON DELETE CASCADE; members.person_id → people CASCADE;
-- sync_items.creator_person_id / sync_comments.author_person_id → people SET NULL; every user FK SET NULL;
-- connections.directory_id → directories SET NULL; members.item_id → sync_items CASCADE;
-- members.task_member_id → task_board_task_members CASCADE.
```

PostgreSQL / SQLite: the same with `TIMESTAMP(0) WITHOUT TIME ZONE` / `BOOLEAN` / `JSON` (`TEXT` on SQLite). Rollback:
drop the new tables and columns (1.24 ignores them).

The exact diff `doctrine:migrations:diff` produces from 1.24 on MySQL 8 (DBAL 4; `app_user` / `INT` stand for your user
table and key; Doctrine adds its own `IDX_…` / `FK_…` names next to the named indexes; the schema adoption test
`SyncSchemaPeopleTest` asserts it):

```sql
CREATE TABLE task_board_sync_people (id VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(254) DEFAULT NULL, email_normalized VARCHAR(254) DEFAULT NULL, remote_role VARCHAR(16) DEFAULT NULL, mapping VARCHAR(16) NOT NULL, mapped_at DATETIME DEFAULT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, email_changed_at DATETIME DEFAULT NULL, anonymised_at DATETIME DEFAULT NULL, seen_kinds SMALLINT DEFAULT 0 NOT NULL, reassign_id VARCHAR(36) DEFAULT NULL, reassign_external TINYINT DEFAULT 0 NOT NULL, provider_user_id VARCHAR(64) NOT NULL, directory_id VARCHAR(36) NOT NULL, mapped_user_id INT DEFAULT NULL, suggested_user_id INT DEFAULT NULL, mapped_by_id INT DEFAULT NULL, reassign_to_user_id INT DEFAULT NULL, INDEX idx_task_board_sync_people_dir_mapping (directory_id, mapping), INDEX idx_task_board_sync_people_dir_email (directory_id, email_normalized), INDEX idx_task_board_sync_people_mapped (mapped_user_id), UNIQUE INDEX uniq_task_board_sync_people_dir_remote (directory_id, provider_user_id), INDEX IDX_AB1F29D72C94069F (directory_id), INDEX IDX_AB1F29D7E5678C70 (suggested_user_id), INDEX IDX_AB1F29D72C770F2E (mapped_by_id), INDEX IDX_AB1F29D771DD50E1 (reassign_to_user_id), PRIMARY KEY (id));
CREATE TABLE task_board_sync_directories (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, reviewed_at DATETIME DEFAULT NULL, anonymous_counter INT DEFAULT 0 NOT NULL, provider VARCHAR(32) NOT NULL, scope_key VARCHAR(64) NOT NULL, workspace_id VARCHAR(64) NOT NULL, reviewed_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_task_board_sync_directories (provider, scope_key, workspace_id), INDEX IDX_65959EB6FC6B21F1 (reviewed_by_id), PRIMARY KEY (id));
CREATE TABLE task_board_sync_members (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, member_role VARCHAR(32) NOT NULL, item_id VARCHAR(36) NOT NULL, task_member_id VARCHAR(36) NOT NULL, person_id VARCHAR(36) NOT NULL, INDEX idx_task_board_sync_members_person (person_id), UNIQUE INDEX uniq_task_board_sync_members_member (task_member_id), INDEX IDX_8F991936126F525E (item_id), PRIMARY KEY (id));
ALTER TABLE task_board_sync_people ADD CONSTRAINT FK_AB1F29D72C94069F FOREIGN KEY (directory_id) REFERENCES task_board_sync_directories (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_people ADD CONSTRAINT FK_AB1F29D763D4A474 FOREIGN KEY (mapped_user_id) REFERENCES app_user (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_people ADD CONSTRAINT FK_AB1F29D7E5678C70 FOREIGN KEY (suggested_user_id) REFERENCES app_user (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_people ADD CONSTRAINT FK_AB1F29D72C770F2E FOREIGN KEY (mapped_by_id) REFERENCES app_user (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_people ADD CONSTRAINT FK_AB1F29D771DD50E1 FOREIGN KEY (reassign_to_user_id) REFERENCES app_user (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_directories ADD CONSTRAINT FK_65959EB6FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES app_user (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_members ADD CONSTRAINT FK_8F991936126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_members ADD CONSTRAINT FK_8F991936ABD1EAF FOREIGN KEY (task_member_id) REFERENCES task_board_task_members (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_members ADD CONSTRAINT FK_8F991936217BBB47 FOREIGN KEY (person_id) REFERENCES task_board_sync_people (id) ON DELETE CASCADE;
ALTER TABLE task_board_connections ADD source_type VARCHAR(8) DEFAULT 'list' NOT NULL, ADD source_id VARCHAR(128) DEFAULT NULL, ADD status_column_map JSON DEFAULT NULL, ADD directory_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513B2C94069F FOREIGN KEY (directory_id) REFERENCES task_board_sync_directories (id) ON DELETE SET NULL;
CREATE INDEX IDX_947E513B2C94069F ON task_board_connections (directory_id);
ALTER TABLE task_board_sync_items ADD unmatched_people JSON DEFAULT NULL, ADD backfill_level SMALLINT DEFAULT 0 NOT NULL, ADD item_kind VARCHAR(16) DEFAULT 'task' NOT NULL, ADD creator_person_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5AD895820F FOREIGN KEY (creator_person_id) REFERENCES task_board_sync_people (id) ON DELETE SET NULL;
CREATE INDEX IDX_3BD8DF5AD895820F ON task_board_sync_items (creator_person_id);
ALTER TABLE task_board_sync_runs ADD time_mode VARCHAR(16) DEFAULT NULL;
ALTER TABLE task_board_sync_comments ADD author_person_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F59A9F6C6E FOREIGN KEY (author_person_id) REFERENCES task_board_sync_people (id) ON DELETE SET NULL;
CREATE INDEX IDX_BDECD5F59A9F6C6E ON task_board_sync_comments (author_person_id);
```

### After the update (sync hosts)

- **Legacy comments (one-off).** Call `TaskSyncCommentLegacyAuthorMigrator::migrate($connection, $batchSize,
  $afterId)` per connection, again with the returned `MigrateReport::$lastId` until `$done`. A comment whose prefix name matches exactly
  one person of the directory loses the line and points at the person; others stay unchanged. Idempotent.
- **People resolver.** Implement `SyncPersonResolverInterface` and set `sync.people.resolver` to share one directory
  per tenant scope and to supply `verified` (see [sync.md](sync.md#people-directory-since-125)).
- **Account erasure: required order.** Since this release the creator of a board, a task and a task document and the
  author of a comment are `ON DELETE SET NULL` with a name snapshot (audits FP-M1 / FP-L7): deleting a user deletes
  no board, task, subtask, document or comment. Still deleted with the user (by `ON DELETE CASCADE`): their task and
  team **memberships** (the rows, not the tasks), their **change-history entries**, and their **own manual time
  entries** (`TaskTimeEntry.user`; time logged by others on their tasks stays). `detachUser()` detaches every board,
  task, document and comment of the user, also on unlinked boards and for content never synced; with
  `anonymise: true` it also clears the name snapshots. **A user deleted without `detachUser()` keeps their name
  (by default the e-mail, from `UserLabel`) in `creator_name` / `author_name`** (audit FP-I11): run `detachUser()`
  with `anonymise: true` first when the name must go, or clear those columns by the old label yourself. Erase in this
  order:
  1. Stop syncs for the user (revoke or unlink their provider tokens) and run `detachUser()` there, so a run in
     progress cannot re-project them.
  2. `TaskSyncPeopleReassigner::detachUser($user, $actor, anonymise: <host policy>)`, repeated with its cursor until
     it is done: creator, comments and sync members move off the user while the user row still exists; then every
     board, task, document and comment of the user is detached (counts in `ReassignReport::$boards`, `$documents`,
     `$creators`, `$comments`). Hosts without `sync.enabled` rely on `ON DELETE SET NULL` (names kept).
  3. TimeTrack: `ImportedTimeEntryWriter::removeForUser($source, $user)` per source (TimeTrack ≥ 1.11), then the other
     TimeTrack erasers.
  4. The host's other erasers.
  5. The user delete last.
  `detachUser()` is idempotent: calling it from both the token eraser and the account chain is safe.
- **Access to imported tasks follows board access.** When a user loses board access, the projected rows stay; they
  stop granting access only if your access checker denies non-members (`BOARD_ACCESS_CHECK` / `TASK_ACCESS_CHECK`).
  The bundle's default participant rule still grants a task's creator and members: a host whose checker does not deny
  non-members should call `reassign($person, null)` (to external) when it wants the content detached. When someone
  gains access, call `TaskSyncPeopleProjector::reprojectForUser($user)` from your membership events.
- **Tracked time.** Nothing is imported until you set `sync.time_entries.sink` and pass `timeEntries: true`; the bundle
  never writes TimeTrack itself. TaskBoard 1.25 works with TimeTrack 1.10; the imported-time total listener needs
  TimeTrack ≥ 1.11.
- **Deleting tasks or boards keeps all time** (owner rule): manual `TaskTimeEntry` rows are detached, TimeTrack
  entries (imported ones included) are untouched. Imported time is deleted only by an explicit manager action
  (spec 208 FR-017), never as a side effect.
- **Only mapped people's time reaches the sink** (audit FP-I2). Entries of people not mapped to a user (`unmatched`,
  `suggested`, `external`, anonymised) are counted in `SyncReport::$time->skippedUnmapped` and never handed to
  `ImportedTimeEntrySinkInterface`. Auto-matching trusts the provider's e-mail: a host that wants imported time only
  for human decisions also checks `$candidate->person->getMapping() === TaskSyncPersonMapping::Manual` in its sink.
- **Unlinking takes the sync lock** (audit FP-L5). `TaskBoardSyncService::unlink()` (and the board eraser, which
  unlinks first) throws `Sync\Exception\SyncBusyException` while a run holds the board's lock; nothing changes. Cancel
  the run (`requestCancel()`) and retry. The connection page shows *Another sync is running*. The unlink is one
  transaction: the connection, its sync rows and the directory prune go together.
- **Checklist subtasks count against your quota** (audit FP-L1). New checklist subtasks go through
  `sync.limit_checker` and `sync.checklists.max_items_per_run` (default 2000). What is held back (warnings
  `checklist_items_capped` / `checklist_items_refused`) is created by a later run.
- **`templates.connection_badge` now applies** to the board, list and Gantt headers (they included the bundle's
  partial by its path). A host that copied `_connection_badge.html.twig` into `templates/bundles/` keeps working; one
  that set the option now gets its template there. Twig global `nowo_task_board_connection_badge_template`.
- **Time phase budget** (audit FP-L4). `sync.time_entries.max_calls_per_run` (default 500) bounds the requests of one
  slice, splits included. A dense window is delivered split by split and the cursor keeps the split position
  (`SyncCursor::$timeSplitStart` / `$timeSplitSeconds`): the budget, a deadline or a rate limit resume from the last
  delivered split (warning `time_paused`). The sink may now get several `accept()` calls for one window and chunk;
  `$windowComplete` is true on the last one.

### Board eraser, imported-time totals and audit fixes (every host)

No schema change and no required configuration for this part.

- **Empty or delete a board without losing time.** `Nowo\TaskBoardBundle\Service\TaskBoardEraser::emptyBoard($board,
  $actor)` / `deleteBoard(...)` return an `EraseProgress`; call again with `$progress->cursor` until `done` (a Messenger
  handler can carry `EraseCursor::toArray()` / `fromArray()`). The actor needs
  `TaskBoardResourceAccessChecker::canManageBoard()`: the board creator or a `security.manage_roles` holder by default;
  a `BOARD_ACCESS_CHECK` listener decides the action `BoardAccessCheckEvent::ACTION_MANAGE` (`'manage'`). A listener
  that grants every action without looking at `getAction()` now also lets those users erase boards.
- **The erase cursor is signed; keep it server-side** (audit FP-L3). Pass back the cursor the eraser returned (or
  `EraseCursor::fromArray($cursor->toArray())`, which keeps the `signature`), for the same board, the same actor and the
  same operation; carry it in a Messenger message or the server session, never through a browser. An unsigned cursor (one
  built with `new EraseCursor(...)`), an altered one, or one of another board, actor or operation is refused with
  `Nowo\TaskBoardBundle\Exception\InvalidEraseCursorException` before anything is touched: start a fresh run with
  `$cursor = null` instead. The container signs with `%kernel.secret%`, so changing the secret invalidates running
  cursors. Code that builds `TaskBoardEraser` by hand should pass `cursorSecret:`; without it a random per-instance key
  is used and cursors resume only through that instance.
- **Your `TASK_ERASED` listener must not delete time.** Remove the task's stored files (`attachmentRefs`), mentions or
  search entries; manual entries are detached and TimeTrack entries keep their task id and snapshots. Imported time is
  deleted only by an explicit manager action in TimeTrack (`ImportedTimeEntryWriter::remove*()`), never on a task or
  board delete.
- **Orphan removal is gone on `TaskBoard::$tasks`** (audit FA-L1). Code that deleted a task with
  `$board->getTasks()->removeElement($task)` must call `$entityManager->remove($task)` instead (the board's `remove`
  cascade is unchanged).
- **Erasure of task titles kept on detached time** (audit FA-L2 / EX-D2): since 1.23.2 deleting a task no longer erases
  its title from the manual time entries logged on it. A host that must honour an erasure request calls
  `TaskTimeEntrySnapshotPurger::purge($boardId, $loggedBefore)` (both optional), which clears the title and external id
  snapshots of detached entries and keeps the minutes, date, user and board id.
- **Task totals with imported time.** With TimeTrack ≥ 1.11 `ImportedTimeAggregatorListener` is registered
  automatically and keeps `total_time_seconds` in step with imported entries; with TimeTrack 1.10 nothing changes.
- **Web sync requests need a readable user id** (audit EX-D1): a user class whose `getId()` is missing or not scalar /
  `Stringable` gets 403 on link, refresh and progress instead of a run as the board's linker.

## To 1.24.0

From **1.23.2** (on 1.23.1 or older, apply [To 1.23.2](#to-1232) first, it has a schema
change): `composer update nowo-tech/task-board-bundle`; no schema change, no required configuration.

- **Single-board tenancy (`tenancy.single_board`).** The tenant's first board is created on the index only for a user
  the create check accepts; others see the empty index.
- **Invalid New board answers 422** (`Cache-Control: no-store, private`). A host listener that rewrote the create redirect (e.g. adding `?new=1` and a flash
  to the index redirect) is no longer needed and no longer fires: an invalid form is now a `422` page with the dialog
  open, values and errors kept. Remove such listeners. Tests that expected a redirect for an invalid form expect `422`.
- **Index page.** `manage/index.html.twig` is now a list page with a *New board* dialog. Overrides of the
  `nowo_task_board_toolbar` block keep working; a page that replaced the whole `body` block can drop its copy and fill
  `index_actions`, `index_board_item` or `index_empty` instead. The template gets `canCreateBoard`, `boardFormFallback`
  and `openCreateBoard` (an override that renders `boardForm` itself is unaffected). The create form is no longer
  shown to users the create check refuses.
- **Tenant-aware create check (opt-in).** To decide "may create a board in this tenant", implement
  `Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface` in the `security.access_checker` service
  and drop any request listener on the create route. Checkers implementing only `TaskBoardAccessCheckerInterface` keep
  working unchanged.
- **Default view (opt-in).** Set `board.default_view_resolver` to a `TaskBoardDefaultViewResolverInterface` service and
  drop any `kernel.request` redirect of the board route. Links that must open the board view add `?view=board`.
- **Dialog header (opt-in).** `board.create_dialog_header: X-…` answers an invalid create from a host dialog with only
  `manage/_board_create_form.html.twig` (`422`).
- **Import help anchor (opt-in).** Add `anchor_from: source` to `help_link` / `nowo_task_board_import_help_link`; a
  template override that only kept the link's anchor on the selected source (e.g. `_task_subtask_import.html.twig`)
  can go.
- **Sync actor (security, `sync.enabled` only).** The default actor resolver now refuses a disabled user
  (`isEnabled()` false) and does not fall back to the board's linker when the named user is refused: such runs end with
  no actor instead of acting as that person. **The 1.23 fallback is gone for named actors:** a request that names an
  actor id that no longer resolves (deleted or erased user) or whose lookup fails is refused too; only runs without an
  actor id (console, schedules that pass none) act as the user who linked the board. A Messenger handler must resolve
  the actor with `SyncActorResolverInterface::actorFor($board, $actorId)` and stop on `null` (see
  [sync.md](sync.md#running-it-in-the-background)); loading the user itself bypasses the refusal. To add the host's own account checks (e.g. anonymised users), set
  `sync.actor_checker` to a `UserCheckerInterface` service (e.g. `security.user_checker.main`); a custom
  `sync.actor_resolver` (such as a host wrapper doing the same) keeps working and can be dropped once the default covers
  its rules.
- `js/task-board.js` and `css/task-board.css` changed: run `assets:install` (or your asset pipeline) after updating.

## To 1.23.2

From **1.23.1**:

```bash
composer update nowo-tech/task-board-bundle
```

### Time entries survive their task

From **1.23.x**. Deleting a task used to delete its manual time entries (`task_board_task_time_entries`); it now
detaches them (`task_id` = NULL) and keeps the task title, board id and external id as snapshots. **Every host must run
a migration** — generate one (`php bin/console doctrine:migrations:diff`, then `doctrine:migrations:migrate`) and add the
backfill below to it, or apply the SQL below. It assumes the default `table_prefix` (`task_board_`); replace the names if
you configured another one. `FK_180A3E3A8DB60186` is the name Doctrine generates for `task_id`; check yours
(`SHOW CREATE TABLE` / `\d`) if the table was created another way.

MySQL / MariaDB:

```sql
ALTER TABLE task_board_task_time_entries DROP FOREIGN KEY FK_180A3E3A8DB60186;
ALTER TABLE task_board_task_time_entries ADD task_title_snapshot VARCHAR(255) DEFAULT NULL, ADD board_id_snapshot VARCHAR(36) DEFAULT NULL, ADD task_external_id VARCHAR(128) DEFAULT NULL, CHANGE task_id task_id VARCHAR(36) DEFAULT NULL;
ALTER TABLE task_board_task_time_entries ADD CONSTRAINT FK_180A3E3A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL;

-- Backfill the snapshots of the existing rows.
UPDATE task_board_task_time_entries e
    INNER JOIN task_board_tasks t ON t.id = e.task_id
    SET e.task_title_snapshot = t.title, e.board_id_snapshot = t.board_id
    WHERE e.task_title_snapshot IS NULL;
UPDATE task_board_task_time_entries e
    INNER JOIN (SELECT task_id, MIN(external_id) AS external_id FROM task_board_task_links
                WHERE import_source IS NOT NULL AND external_id IS NOT NULL GROUP BY task_id) l ON l.task_id = e.task_id
    SET e.task_external_id = l.external_id
    WHERE e.task_external_id IS NULL;
```

PostgreSQL:

```sql
ALTER TABLE task_board_task_time_entries DROP CONSTRAINT FK_180A3E3A8DB60186;
ALTER TABLE task_board_task_time_entries ADD task_title_snapshot VARCHAR(255) DEFAULT NULL;
ALTER TABLE task_board_task_time_entries ADD board_id_snapshot VARCHAR(36) DEFAULT NULL;
ALTER TABLE task_board_task_time_entries ADD task_external_id VARCHAR(128) DEFAULT NULL;
ALTER TABLE task_board_task_time_entries ALTER task_id DROP NOT NULL;
ALTER TABLE task_board_task_time_entries ADD CONSTRAINT FK_180A3E3A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL NOT DEFERRABLE;

-- Backfill the snapshots of the existing rows.
UPDATE task_board_task_time_entries e
    SET task_title_snapshot = t.title, board_id_snapshot = t.board_id
    FROM task_board_tasks t
    WHERE t.id = e.task_id AND e.task_title_snapshot IS NULL;
UPDATE task_board_task_time_entries e
    SET task_external_id = l.external_id
    FROM (SELECT task_id, MIN(external_id) AS external_id FROM task_board_task_links
          WHERE import_source IS NOT NULL AND external_id IS NOT NULL GROUP BY task_id) l
    WHERE l.task_id = e.task_id AND e.task_external_id IS NULL;
```

SQLite (a foreign key cannot be altered: the table is rebuilt; `<users>` is the table of your `user_class`, and
`FK_180A3E3AA76ED395` / `IDX_180A3E3AA76ED395` the generated names for `user_id`):

```sql
PRAGMA foreign_keys = OFF;
BEGIN;
CREATE TABLE task_board_task_time_entries_new (id VARCHAR(36) NOT NULL, task_title_snapshot VARCHAR(255) DEFAULT NULL, board_id_snapshot VARCHAR(36) DEFAULT NULL, task_external_id VARCHAR(128) DEFAULT NULL, minutes INTEGER NOT NULL, logged_at DATETIME NOT NULL, description VARCHAR(512) DEFAULT NULL, task_id VARCHAR(36) DEFAULT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_180A3E3A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_180A3E3AA76ED395 FOREIGN KEY (user_id) REFERENCES <users> (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
-- Copy the rows and backfill the snapshots in one go.
INSERT INTO task_board_task_time_entries_new (id, task_title_snapshot, board_id_snapshot, task_external_id, minutes, logged_at, description, task_id, user_id)
    SELECT e.id, t.title, t.board_id,
           (SELECT MIN(l.external_id) FROM task_board_task_links l WHERE l.task_id = e.task_id AND l.import_source IS NOT NULL AND l.external_id IS NOT NULL),
           e.minutes, e.logged_at, e.description, e.task_id, e.user_id
    FROM task_board_task_time_entries e LEFT JOIN task_board_tasks t ON t.id = e.task_id;
DROP TABLE task_board_task_time_entries;
ALTER TABLE task_board_task_time_entries_new RENAME TO task_board_task_time_entries;
CREATE INDEX IDX_180A3E3A8DB60186 ON task_board_task_time_entries (task_id);
CREATE INDEX IDX_180A3E3AA76ED395 ON task_board_task_time_entries (user_id);
COMMIT;
PRAGMA foreign_keys = ON;
```

SQLite applies `ON DELETE SET NULL` only with `PRAGMA foreign_keys = ON` on the connection; deletes through the ORM
detach the entries either way.

What changes for host code:

- **Queries over time entries must not inner-join the task.** A detached entry has `task_id` = NULL: a timesheet,
  report or export that does `INNER JOIN task_board_tasks` (DQL `JOIN e.task t`) silently drops it. Use a left join
  (`LEFT JOIN e.task t`) and read the title / board from `TaskTimeEntry::getTaskTitle()` / `getBoardId()` (live values,
  or the snapshots once the task is gone), or the `task_title_snapshot` / `board_id_snapshot` columns in SQL. Filter a
  board's time on `board_id_snapshot` (with `t.board_id` as a fallback for rows you did not backfill) instead of
  `t.board_id` alone.
- `TaskTimeEntry::getTask()` returns `?Task`: guard every `$entry->getTask()->…` (Twig: `entry.task.title` on a
  detached entry renders empty — use `entry.taskTitle`).
- The bundle's own pages read entries only through `task.timeEntries` (the task detail), so they never meet a detached
  entry.
- `TaskTimeEntryRepositoryInterface` gained `remove(TaskTimeEntry $entry): void`, and `TaskTimeLogger` takes it as a
  third constructor argument (autowired; only hosts that build the logger or implement the interface themselves must
  adapt). `Task::removeTimeEntry()` no longer deletes the entry on flush (no orphan removal): delete it with the
  repository.
- Deleting a board (`TaskBoardRepositoryInterface::remove()`) keeps the time of its tasks the same way.
- TimeTrackBundle's `TimeEntry` (timers) already kept `task_title_snapshot` / `board_id_snapshot` and survived the
  task; manual entries now behave the same.

## To 1.23.1

From **1.23.0**:

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.23.0

From **1.22.x** (run the 1.22.0 migration and backfill first if you come from 1.21 or older):

```bash
composer update nowo-tech/task-board-bundle
```

`symfony/http-client` is now required (Composer installs it). Generate and run a migration
(`php bin/console doctrine:migrations:diff`, then `doctrine:migrations:migrate`), or apply the SQL below. It assumes the
default `table_prefix` (`task_board_`); replace the names if you configured another one.

### Schema change for every host: archived tasks

MySQL / MariaDB:

```sql
ALTER TABLE task_board_tasks ADD archived_at DATETIME DEFAULT NULL, ADD archive_reason VARCHAR(16) DEFAULT NULL;
CREATE INDEX task_board_tasks_board_archived_idx ON task_board_tasks (board_id, archived_at);
```

PostgreSQL:

```sql
ALTER TABLE task_board_tasks ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE task_board_tasks ADD archive_reason VARCHAR(16) DEFAULT NULL;
CREATE INDEX task_board_tasks_board_archived_idx ON task_board_tasks (board_id, archived_at);
```

Existing tasks stay active (`archived_at` is NULL). `findByBoard()`, `findByBoardWithin()`, `countByBoard()` and the
TimeTrack task list now leave archived tasks out; a custom `TaskRepositoryInterface` should do the same (and may implement
`ArchiveAwareTaskRepositoryInterface`, which imports and the sync use to see archived tasks).

### Schema change with `sync.enabled: true` only: the sync tables

These tables are mapped only when the sync is enabled. `<users>` is the table of your `user_class`, and its id type must
match (`INT` here).

MySQL / MariaDB:

```sql
CREATE TABLE task_board_connections (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, last_run_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, watermark DATETIME DEFAULT NULL, sync_cursor JSON DEFAULT NULL, running_run_id VARCHAR(36) DEFAULT NULL, heartbeat_at DATETIME DEFAULT NULL, provider VARCHAR(32) NOT NULL, import_scope VARCHAR(64) NOT NULL, connection_ref VARCHAR(191) NOT NULL, container_id VARCHAR(128) NOT NULL, container_label VARCHAR(255) NOT NULL, container_path JSON NOT NULL, options JSON NOT NULL, board_id VARCHAR(36) NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_task_board_connections_board (board_id), INDEX IDX_947E513BB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
CREATE TABLE task_board_sync_items (id VARCHAR(36) NOT NULL, field_hashes JSON NOT NULL, conflict_hashes JSON NOT NULL, remote_archived_at DATETIME DEFAULT NULL, last_seen_run_id VARCHAR(36) DEFAULT NULL, board_id VARCHAR(36) NOT NULL, external_id VARCHAR(128) NOT NULL, remote_updated_at DATETIME NOT NULL, connection_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) DEFAULT NULL, INDEX idx_task_board_sync_items_task (task_id), UNIQUE INDEX uniq_task_board_sync_items_board_ext (board_id, external_id), INDEX IDX_3BD8DF5ADD03F01 (connection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
CREATE TABLE task_board_sync_runs (finished_at DATETIME DEFAULT NULL, heartbeat_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, cancel_requested TINYINT NOT NULL, report JSON NOT NULL, run_order BIGINT NOT NULL, id VARCHAR(36) NOT NULL, board_id VARCHAR(36) NOT NULL, run_trigger VARCHAR(16) NOT NULL, mode VARCHAR(16) NOT NULL, dry_run TINYINT NOT NULL, started_at DATETIME NOT NULL, connection_id VARCHAR(36) NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_task_board_sync_runs_board (board_id, started_at), INDEX IDX_26B4BC74DD03F01 (connection_id), INDEX IDX_26B4BC7410DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
CREATE TABLE task_board_sync_comments (id VARCHAR(36) NOT NULL, remote_id VARCHAR(128) NOT NULL, body_hash VARCHAR(64) NOT NULL, local_hash VARCHAR(64) NOT NULL, item_id VARCHAR(36) NOT NULL, comment_id VARCHAR(36) NOT NULL, UNIQUE INDEX uniq_task_board_sync_comments_item_remote (item_id, remote_id), INDEX IDX_BDECD5F5126F525E (item_id), INDEX IDX_BDECD5F5F8697D13 (comment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
CREATE TABLE task_board_sync_attachments (id VARCHAR(36) NOT NULL, remote_id VARCHAR(128) NOT NULL, remote_size BIGINT DEFAULT NULL, remote_updated_at DATETIME DEFAULT NULL, stored_ref VARCHAR(191) NOT NULL, stored_url VARCHAR(2048) NOT NULL, name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size BIGINT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, item_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) NOT NULL, link_id VARCHAR(36) DEFAULT NULL, UNIQUE INDEX uniq_task_board_sync_attachments_task_remote (task_id, remote_id), INDEX IDX_A44E84EF126F525E (item_id), INDEX IDX_A44E84EF8DB60186 (task_id), INDEX IDX_A44E84EFADA40271 (link_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513BE7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE;
ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513BB03A8386 FOREIGN KEY (created_by_id) REFERENCES <users> (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5ADD03F01 FOREIGN KEY (connection_id) REFERENCES task_board_connections (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_runs ADD CONSTRAINT FK_26B4BC74DD03F01 FOREIGN KEY (connection_id) REFERENCES task_board_connections (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_runs ADD CONSTRAINT FK_26B4BC7410DAF24A FOREIGN KEY (actor_id) REFERENCES <users> (id) ON DELETE SET NULL;
ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F5126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F5F8697D13 FOREIGN KEY (comment_id) REFERENCES task_board_task_comments (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EF126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EF8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE;
ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EFADA40271 FOREIGN KEY (link_id) REFERENCES task_board_task_links (id) ON DELETE SET NULL;
```

PostgreSQL:

```sql
CREATE TABLE task_board_connections (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, watermark TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sync_cursor JSON DEFAULT NULL, running_run_id VARCHAR(36) DEFAULT NULL, heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, provider VARCHAR(32) NOT NULL, import_scope VARCHAR(64) NOT NULL, connection_ref VARCHAR(191) NOT NULL, container_id VARCHAR(128) NOT NULL, container_label VARCHAR(255) NOT NULL, container_path JSON NOT NULL, options JSON NOT NULL, board_id VARCHAR(36) NOT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_task_board_connections_board ON task_board_connections (board_id);
CREATE INDEX IDX_947E513BB03A8386 ON task_board_connections (created_by_id);
CREATE TABLE task_board_sync_items (id VARCHAR(36) NOT NULL, field_hashes JSON NOT NULL, conflict_hashes JSON NOT NULL, remote_archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_seen_run_id VARCHAR(36) DEFAULT NULL, board_id VARCHAR(36) NOT NULL, external_id VARCHAR(128) NOT NULL, remote_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, connection_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_task_board_sync_items_task ON task_board_sync_items (task_id);
CREATE UNIQUE INDEX uniq_task_board_sync_items_board_ext ON task_board_sync_items (board_id, external_id);
CREATE INDEX IDX_3BD8DF5ADD03F01 ON task_board_sync_items (connection_id);
CREATE TABLE task_board_sync_runs (finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, heartbeat_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(16) NOT NULL, cancel_requested BOOLEAN NOT NULL, report JSON NOT NULL, run_order BIGINT NOT NULL, id VARCHAR(36) NOT NULL, board_id VARCHAR(36) NOT NULL, run_trigger VARCHAR(16) NOT NULL, mode VARCHAR(16) NOT NULL, dry_run BOOLEAN NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, connection_id VARCHAR(36) NOT NULL, actor_id INT DEFAULT NULL, PRIMARY KEY (id));
CREATE INDEX idx_task_board_sync_runs_board ON task_board_sync_runs (board_id, started_at);
CREATE INDEX IDX_26B4BC74DD03F01 ON task_board_sync_runs (connection_id);
CREATE INDEX IDX_26B4BC7410DAF24A ON task_board_sync_runs (actor_id);
CREATE TABLE task_board_sync_comments (id VARCHAR(36) NOT NULL, remote_id VARCHAR(128) NOT NULL, body_hash VARCHAR(64) NOT NULL, local_hash VARCHAR(64) NOT NULL, item_id VARCHAR(36) NOT NULL, comment_id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_task_board_sync_comments_item_remote ON task_board_sync_comments (item_id, remote_id);
CREATE INDEX IDX_BDECD5F5126F525E ON task_board_sync_comments (item_id);
CREATE INDEX IDX_BDECD5F5F8697D13 ON task_board_sync_comments (comment_id);
CREATE TABLE task_board_sync_attachments (id VARCHAR(36) NOT NULL, remote_id VARCHAR(128) NOT NULL, remote_size BIGINT DEFAULT NULL, remote_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, stored_ref VARCHAR(191) NOT NULL, stored_url VARCHAR(2048) NOT NULL, name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size BIGINT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, item_id VARCHAR(36) NOT NULL, task_id VARCHAR(36) NOT NULL, link_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id));
CREATE UNIQUE INDEX uniq_task_board_sync_attachments_task_remote ON task_board_sync_attachments (task_id, remote_id);
CREATE INDEX IDX_A44E84EF126F525E ON task_board_sync_attachments (item_id);
CREATE INDEX IDX_A44E84EF8DB60186 ON task_board_sync_attachments (task_id);
CREATE INDEX IDX_A44E84EFADA40271 ON task_board_sync_attachments (link_id);
ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513BE7EC5785 FOREIGN KEY (board_id) REFERENCES task_board_boards (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_connections ADD CONSTRAINT FK_947E513BB03A8386 FOREIGN KEY (created_by_id) REFERENCES <users> (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5ADD03F01 FOREIGN KEY (connection_id) REFERENCES task_board_connections (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_sync_items ADD CONSTRAINT FK_3BD8DF5A8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE task_board_sync_runs ADD CONSTRAINT FK_26B4BC74DD03F01 FOREIGN KEY (connection_id) REFERENCES task_board_connections (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_sync_runs ADD CONSTRAINT FK_26B4BC7410DAF24A FOREIGN KEY (actor_id) REFERENCES <users> (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F5126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_sync_comments ADD CONSTRAINT FK_BDECD5F5F8697D13 FOREIGN KEY (comment_id) REFERENCES task_board_task_comments (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EF126F525E FOREIGN KEY (item_id) REFERENCES task_board_sync_items (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EF8DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE task_board_sync_attachments ADD CONSTRAINT FK_A44E84EFADA40271 FOREIGN KEY (link_id) REFERENCES task_board_task_links (id) ON DELETE SET NULL NOT DEFERRABLE;
```

Boards imported earlier from a ClickUp CSV or JSON file are adopted by their first sync without duplicates, through the
import links of 1.22 (`import_source` = `clickup`).

### Provider sync (opt-in)

Nothing changes until you set `sync.enabled: true` with a `credential_resolver`. See [sync.md](sync.md).

- **Access listeners:** `BoardAccessCheckEvent` has an action (`getAction()`). Your `BOARD_ACCESS_CHECK` listener sees
  `ACTION_MANAGE_CONNECTION` when someone links, refreshes or unlinks a board. A listener that grants every action
  without looking at it lets everyone who can edit the board manage its connection: check the action.
- **New routes** (`board_connection*`, only with the sync) and **`task_restore`** (always). A host that lists its routes
  explicitly or overrides `routes.*` gets the defaults.
- **Templates:** `board.html.twig`, `list.html.twig` and `gantt.html.twig` gained the block `nowo_task_board_connection`
  (renders nothing without the sync), `list.html.twig` the folded "Archived tasks" section (`nowo_task_board_archived`)
  and the task page an "archived" notice. Overrides of these pages should add them.
- **Translations:** new keys `task_board.sync.*`, `task_board.history.type.sync_conflict|archived|restored`,
  `task_board.link.attachment`, `task_board.list.archived`, `task_board.task.archive_reason.*`,
  `task_board.task.archived_notice`, `task_board.task.restore`, `task_board.flash.task_restored` (7 locales).
- **Test fixtures:** `tests/Fixtures/clickup/` moved to `tests/Fixtures/ClickUp/`.
- **Rendering synced text:** if task text is rendered with league/commonmark, set `allow_unsafe_links: false` (and
  `html_input: escape` or `strip`). The sanitizer empties unsafe link targets and removes raw HTML, but the renderer is
  the first line of defence. See [SECURITY](SECURITY.md#provider-sync-host-duties-and-known-limits) for serving stored
  files (`nosniff`, `attachment`), a custom `sync.http.client` (must not log URLs) and the other host duties.
- **Run limits:** a second request into a running run is Busy (also a slice of the same run); a slice reads at most
  `ceil(max_tasks_per_run / 100) + 1` task pages and a run at most `sync.max_pages_per_run` (new, 1000) over all its
  slices, and fails with `response_too_large` beyond; a page that brings no new
  task ends the list (warning `paging_stopped`, the watermark stays). Comments are read up to `max_comments_per_task`
  rows.
- **Synced text:** raw HTML in remote text is now escaped (shown as text) instead of removed, and an unsafe link or
  reference definition stays as plain text.
- **Assignees:** a row resolves at most 50 assignees (file import and sync); unresolved ones are listed up to 10, then
  counted.
- **Attachment hosts:** `sync.providers.clickup.attachment_hosts` refuses a pattern that is not a host of two labels at
  least (`*.com` is refused at container build).

### Known unconfirmed ClickUp behaviour

The ClickUp adapter was built from the API v2 documentation; its request shapes are unconfirmed against the live API
(owner decision 2026-11-04) and validated by Beacon after deploy. The adapter is defensive on each point:

- **Order of the task pages** (`order_by=updated&reverse=true`, meant to list the oldest update first): the run does
  not depend on it — the watermark is the newest date of all pages read, and the next run's overlap window re-reads the
  edge.
- **Attachments in the list answer and their fields:** only `id` and `url` are required; `title` / `name`, `size`,
  `mimetype` and `date` are read when present, extra fields are ignored, and a task without an `attachments` list has
  no files synced (no extra call).
- **`last_page`:** used when the answer has it; otherwise paging goes on until an empty page (a short page is not
  trusted to be the last), always within the run's page caps (`max_pages_per_run`).
- **Comment paging:** the documented `start` / `start_id` of the oldest comment; it stops on a page shorter than 25,
  an empty page, a cursor that does not move, or the comment caps.

Unexpected answer shapes are logged at debug level with their keys only, never a value. The recording script
(`make record-clickup-fixtures`) stays for a later confirmation.

### Task member pictures (opt-in)

With the default `NullTaskMemberAvatarResolver` the member chips are unchanged. To show pictures, alias
`Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface` to your service ([USAGE](USAGE.md#task-member-pictures-since-123)).

- The six templates with a member chip (`_task_card`, `list`, `_task_blocks`, `_task_members_modal`, `_task_comment`,
  `_task_history_section`) now include `manage/_task_member_avatar.html.twig`. An override of one of them that copies the
  old chip keeps working without pictures; include the partial to get them.
- URLs must be `https://…` (no user info, no IPv6 literal, port 1–65535) or a same-origin path. `http:`, `data:`, relative
  paths, `//…`, quotes and control characters are dropped (the initial stays).
- Your CSP `img-src` must allow the origins your URLs point or redirect to. Nothing inline is added.
- One more CSS block in `task-board.css` (`.nowo-task-member-avatar*`) and the fallback in `task-board.js`.

## To 1.22.2

From **1.22.1**: `composer update nowo-tech/task-board-bundle`. No schema change.

- **Imports are written in batches (audit EB-L7).** The import orchestrator flushes every 200 rows and lets those
  tasks leave the entity manager, inside **one database transaction**: memory stays flat, and a failure anywhere rolls
  the whole import back (as the single flush of 1.22 did). The board, its columns, the users and the task imported
  into stay managed. A host listening to Doctrine flush events sees one flush per batch.
- **`import.max_json_nodes` default 1 800 000 → 4 000 000.** The 1.22.1 figure of about 40 KB per task (1 200 tasks
  at 128 MB, "lower `max_rows` to 1200") was measured with query logging on. Without it 1.22.1 still cost about 26 KB
  per task and still could not import 5 000 rows at 128 MB from any source: the batches of 1.22.2 are what fixes it.
  Measured through the import controller with a production kernel (no query logging) and an 18 MB request baseline,
  a `memory_limit` of 128 MB now takes:
  - **CSV:** 5 000 rows, the default `max_rows` (peak +32 MB);
  - **Trello JSON:** 5 000 cards with their checklists and no actions (≈ 5.7 MB, weight ≈ 3.1 M; peak +68 MB);
    a board export with its actions (Trello adds the last 1 000 by default) weighs more: 5 000 cards plus 1 000 actions weigh about 4.3 M and are refused cleanly; export without actions, or set `max_json_nodes: 4500000` with a `memory_limit` of 256 MB;
  - **the bundle's JSON:** 5 000 tasks;
  - **ClickUp API JSON:** about 2 700 tasks (≈ 5.7 MB; peak +61 MB). A 5 000-task export (10.5 MB, weight ≈ 7.4 M)
    is refused cleanly by the default weight: decoding it alone needs more than 128 MB. Import it with
    `max_json_nodes: 7500000` and a `memory_limit` of 256 MB.
  
  The default `max_json_nodes` (4 000 000) keeps every hostile JSON shape the audits tried under +70 MB at that
  limit. A host whose requests already use more than about 55 MB before the import should lower `max_json_nodes` (to about 2 500 000) or raise `memory_limit`.
- Two imports into the same board at the same time can each create a task for the same source id (they do not lock the board; this was already so before 1.22.2): import one file at a time.

## To 1.22.1

From **1.22.0**: `composer update nowo-tech/task-board-bundle`. No schema change.

- **New import limits (audits DL-L6, EB), checked before anything is built:**
  - `import.max_columns` (default 512): the columns of a CSV header or row;
  - `import.max_cells` (default 1 000 000): the cells of a CSV file, **columns × rows, empty cells included**;
  - `import.max_rows` (unchanged, 5000) now also counts blank lines of a CSV and the items of a JSON file before they
    are mapped;
  - `import.max_json_nodes` (default 1 800 000): the weight of a JSON file before it is decoded (strings by their
    length, list and object tables counted at their doubled size, as PHP grows them; about 16 bytes of decoded memory per unit); JSON is decoded with a depth limit of 64.

  **Memory.** Measured end to end through the import controller (an 18 MB request baseline included), the defaults keep
  every JSON shape the audits tried, including lists that grow past a power of two, under a `memory_limit` of 128 MB.
  The import itself (tasks, history, links) costs about 40 KB per task whatever the source, so a 128 MB request takes
  **about 1 200 tasks**:
  - **JSON:** the default weight takes a realistic ClickUp API export of about 1 200 tasks (≈ 2.6 MB); a heavier file is
    refused (`json_too_large`). A 5 000-task export weighs about 7.4 M: set `max_json_nodes: 7500000` **and**
    `memory_limit` to 512 MB (it peaks above 230 MB; 256 MB is not enough).
  - **CSV:** `max_rows` stays 5000, but a 128 MB request imports about 1 200–1 500 rows (3 000 rows exhaust it); 5 000 rows
    need a `memory_limit` of 256 MB (peak about 220 MB). Hosts on 128 MB should lower `max_rows` to 1200.
  - **Wide CSV:** 512 columns and 1 000 000 cells (columns × rows, empty cells included). A Jira export with **all
    fields** is wide: about 250–300 columns, so 5 000 rows of it are 1.25–1.5 M cells and are refused
    (`too_many_cells`); a Jira export with more than 512 columns (many custom fields) is refused as
    `too_many_columns`. Export fewer columns, split the file, or raise the limits together with PHP's `memory_limit`
    (a kept cell costs about 70 bytes on top of the tasks).
  
  ```yaml
  nowo_task_board:
      import:
          max_columns: 1024        # very wide Jira exports
          max_cells: 2000000       # e.g. 300 columns × 5 000 rows = 1 500 000; with memory_limit 512M
          max_json_nodes: 7500000  # 5 000-task ClickUp / Trello JSON; with memory_limit 512M
  ```
- **`nowo:task-board:import`** refuses a file larger than the upload form's 20 MB (`TaskBoardImportCommand::MAX_FILE_BYTES`).
- **Translated limit errors:** the limit errors use the new keys `task_board.import.error.too_many_rows`,
  `cell_too_long`, `too_many_columns`, `too_many_cells` and `json_too_large` (parameter `%max%`). A host overriding the
  catalogue should add them; without them the English text is shown.
- **JSON error texts** changed slightly (`Invalid ClickUp JSON: Syntax error`, `The file is not valid JSON: Syntax
  error`); hosts that match them should match the prefix.
- A custom `DelimitedTableParser` service definition should pass `$maxColumns` / `$maxCells`, and a custom JSON
  importer definition the `JsonImportGuard` service, or they keep the defaults.
- **`help_link`** (and the `nowo_task_board_import_help_link` global): a URL with white space, a control character or a
  backslash renders `#`.

## To 1.22.0

From **1.21.0**:

```bash
composer update nowo-tech/task-board-bundle
```

**Schema change (every host):** a nullable import scope on the task links, and an index for the matching key.
Generate and run a migration (`php bin/console doctrine:migrations:diff` then `doctrine:migrations:migrate`), or apply
(MySQL):

```sql
ALTER TABLE task_board_task_links ADD import_source VARCHAR(64) DEFAULT NULL;
CREATE INDEX idx_task_board_task_links_import ON task_board_task_links (import_source, external_id);
```

The demo ships it as `demo/symfony8/migrations/Version20261103120000.php`.

**Imports are idempotent upserts** (every source: ClickUp CSV / JSON, Jira CSV, Trello JSON, the bundle's CSV / JSON).
A row imported before **updates** its task instead of being skipped (or, with `skipExisting: false` / `--force`,
duplicated); importing the same file twice changes nothing. Details: [docs/import/README.md — Re-importing](import/README.md#re-importing-idempotent-upsert-since-122).

- **Matching key:** (import scope, source id) stored on the task link — on a board import `import_source` =
  `clickup` (CSV and JSON share it), `jira`, `trello` or `taskboard`; on an import **into a task** (Import subtasks)
  `<provider>@<task id>` for every source, so it only ever matches the tasks an earlier import put under that task. Rows without an id are matched by a fingerprint (scope, parent, normalized title, source creation date)
  and the import warns that they are.
- **Precedence:** the source wins for every field it has a value for (title, column by status, priority, description,
  start, due, estimate, tags, assignees, parent row); a field the row leaves empty keeps the task's value, so changes
  made in the application survive unless the source sets that field. Assignees from the source replace the task's
  assignees (other member roles are untouched). Dependencies are only added. A row without a parent keeps its task
  where it is (the task you import into only places new tasks).
- **Across boards:** on a board import, a source id already imported into another board of the same tenant is
  skipped (no move, no copy); so is a row without an id whose fingerprint is already on another board of the tenant.
  The warning is a count only ("N row(s) were skipped: already imported elsewhere"): no board, no title. Boards of
  other tenants are not looked at; the own format and imports into a task are board- / task-local.
- **Who may change what (security):** a re-import only updates, moves, re-assigns or links tasks the importing user
  may edit (`TaskBoardResourceAccessChecker::canEditTask()`, so `TASK_ACCESS_CHECK` listeners and `markReadOnly()`
  apply). A row matching a task the user cannot edit is skipped ("N row(s) were skipped: they match tasks you cannot
  edit", without titles); such a task is not used as a parent or a dependency target either. Warnings do not name
  boards or stored titles.
- **Links written before 1.22 are not matched** (a link without `import_source` could have been typed in the link
  form, so it is not trusted, audit DL-L2). A re-import of an older ClickUp / Jira / Trello export therefore creates
  its tasks again, unless you run this one-off backfill **once, right after the upgrade** (MySQL). It only stamps
  links whose label is the one older imports wrote, created within a minute of their task, on tasks whose history
  has no link added or edited by hand:

  ```sql
  UPDATE task_board_task_links l JOIN task_board_tasks t ON t.id = l.task_id
     SET l.import_source = CASE
         WHEN l.label = CONCAT('jira_csv #', l.external_id) THEN 'jira'
         WHEN l.label = CONCAT('trello_json #', l.external_id) THEN 'trello'
         ELSE 'clickup' END
   WHERE l.import_source IS NULL
     AND l.label IN (CONCAT('clickup_csv #', l.external_id), CONCAT('clickup_json #', l.external_id),
                     CONCAT('jira_csv #', l.external_id), CONCAT('trello_json #', l.external_id))
     AND ABS(TIMESTAMPDIFF(SECOND, l.created_at, t.created_at)) <= 60
     AND NOT EXISTS (SELECT 1 FROM task_board_task_change_history h
                      WHERE h.task_id = l.task_id AND h.change_type IN ('link_added', 'link_updated'));
  ```

  The statement is **idempotent and safe to re-run**: it only touches rows with `import_source IS NULL`, and every row
  it stamps leaves that set, so a second run (or a re-run of a migration that holds it) updates 0 rows; the other
  conditions never change between runs either. It is a single `UPDATE`, atomic on InnoDB, so it can run in a
  non-transactional migration right after the `ALTER TABLE` / `CREATE INDEX` above.

  The SQL is for MySQL / MariaDB (`UPDATE … JOIN`, `TIMESTAMPDIFF`); adapt it for other platforms. It names the
  default tables (`task_board_tasks`, `task_board_task_links`, …): replace them if you configured a table prefix. Its
  "edited by hand" exclusion reads the task history, so run it before purging history.

  A task skipped by these conditions is created again by the next re-import (delete the old one if you do not want
  both). **Not recognised at all:** anything imported before 1.22 with the bundle's own format (`taskboard_csv` /
  `taskboard_json`), on a board or into a task — re-importing that file **re-creates** those tasks (the old ones
  stay); delete them first if you do not want both. Since 1.22 the link form can no longer change the external id of
  a link an import wrote.
- **Deprecated, ignored:** `TaskImportOptions::$skipExisting`, `TaskImportFormData::$skipExisting` and the command's
  `--force` (it prints a note). They stay for backward compatibility and will be removed in 2.0. The import form no
  longer has the **Skip tasks that were already imported** checkbox (`task_board.import.form.skip_existing` is kept,
  unused, for host overrides).
- **Result:** `TaskImportResult` gained `updated` and `unchanged` (and `withUpdated()` / `withUnchanged()`);
  `skipped` now counts rows of another board and repeated ids of the file. The flash messages
  `task_board.flash.import_summary` / `subtask_import_summary` gained `%updated%` and `%unchanged%` (host overrides
  with the old placeholders keep working). The `TaskBoard import finished` log line has `updated` / `unchanged`.
- **Start from the creation date:** a new task whose row has no start date starts on the source's creation date
  (ClickUp `Date Created` / `date_created`, Jira `Created`), so its Gantt bar no longer starts on the import day. On
  create only: a re-import never sets the start from the creation date.
- **Assignees:** the source replaces the application's assignees of a matched task when the row names someone who
  resolves (other member roles are untouched); otherwise they are kept. With an `assignee_provider`, an e-mail only
  assigns a user the provider accepts (`canBeAssigned()`, as the member form); an unknown and a non-assignable e-mail
  get the same warning ("cannot be assigned on this board").
- **Limits (new config):** `import.max_rows` (default 5000 rows per file) and `import.max_cell_length` (default 65536
  bytes per CSV cell); a larger file is refused with an error. Parent chains are ordered without recursion and the
  ancestor check of a move walks the whole chain.
- **New mappings:** ClickUp CSV `Start Date`, `Date Created`, `Dependencies` / `Waiting On` / `Blocking`, assignees by
  name, millisecond and text dates; ClickUp JSON `start_date`, `date_created`, `dependencies`, assignee user names;
  Jira start date, `Created`, "Blocks" issue links, display names (and `Time Spent` is no longer read as an estimate);
  Trello `start` and members. Names resolve through `assignee_provider` (unambiguous display-name match only).
- **Dates without a time** are now midnight (they took the time of the import before), and a 10-digit number is a Unix
  timestamp in seconds (only 11+ digits are milliseconds). Relative text ("next monday") and years outside 1970-2200
  are not dates. Tasks imported before keep their dates until a re-import
  sets the source's.
- **Custom importers / services:** `ImportedTaskDto` gained `createdAt`, `assigneeNames` and `hasPriority` (default
  `true`: the row's priority is applied, as before). `TaskImportOrchestrator` gained optional constructor arguments
  (`assigneeProvider`, `linkLookup`, `updater`, `accessChecker`, `maxRows`); without `linkLookup` the cross-board
  rule is not checked and without `accessChecker` per-task rights are not checked (manual instantiation only: the
  container wires both). New service alias `TaskImportLinkLookupInterface` → `DoctrineTaskImportLinkLookup`. New
  `Task::setParent()` and `TaskLink::getImportSource()` / `setImportSource()`.

## To 1.21.0

From **1.20.1**:

```bash
composer update nowo-tech/task-board-bundle
```

From **1.20.1** (proposed 1.21.0):

```bash
composer update nowo-tech/task-board-bundle
```

No configuration change is required. Route names, paths and template paths are unchanged.

- **Task cap (new default):** the board, list, Gantt and task pages load at most `board.max_tasks` tasks per board
  (default **500**: the first cards of every column, in position order) and say "Showing N of M tasks" above the
  view when a board has more. Raise it for very large boards:

  ```yaml
  nowo_task_board:
      board:
          max_tasks: 1000
  ```

- **Template overrides:** `manage/board.html.twig`, `manage/list.html.twig` and `manage/gantt.html.twig` include the
  new `manage/_task_window_notice.html.twig` (variable `taskWindow`) below the page header. An override keeps working;
  add the include to show the notice.
- **Custom task repository:** if you alias `TaskRepositoryInterface` to your own class, it keeps working unbounded
  (1.20 behaviour). Implement `BoundedTaskRepositoryInterface` too to get the cap and the fetch-joined task page.
- **Logging:** the controller and the importer now log through the autowired `Psr\Log\LoggerInterface`; route the
  `TaskBoard write` / `TaskBoard import` lines with Monolog if you want them elsewhere (CONFIGURATION, Logging).

## To 1.20.1

From **1.20.0**: no change needed. The priority, time entry, dependency, comment, list filter and CSRF-only action
forms are now FormKit Types on the `task_board` profile; their markup is unchanged.

## To 1.20.0

From **1.19.1**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. No schema change.

- **Icons**: a host on `ux_icon` imports `tabler:corner-left-up` (the parent step); `chevron-left`, `chevron-right` and
  `subtask` were already used.
- **Estimate field**: `task_form[estimatedMinutes]` is a text field (a duration, e.g. *2 h 30 min*); a bare number is
  still minutes, so scripts posting minutes keep working. Tests reading the rendered value expect *1 h 30 min*, not *90*.
- **Templates**: a host overriding `_task_blocks.html.twig` gets the new block `nowo_task_navigation` after the toolbar;
  the Start and Due rows are one *Dates* row (`.nowo-task-detail__attr--dates`). The subtask tree's column badge lost
  `badge bg-secondary-lt` (now `.nowo-task-badge`); the head's deviation pill is `.nowo-task-remaining--left|--over`.
- **Drawers**: read `data-title` (not the card text) for the heading, and step a subtask through the panel's
  `.nowo-task-nav--panel [data-nav="subtasks"] a[rel=prev|next]` links when it is not one of the view's tasks.
- **Task page links from the list**: `?from=list` keeps the list order; a host linking to the page from its own list
  may add it.

## To 1.19.1

From **1.19.0**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. CSS only: a host
that draws the task panel without card frames resets `.nowo-task-detail__history > :not(summary)` (margin-inline).

## To 1.19.0

From **1.18.1**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. No schema change;
comments and descriptions look the same until you plug a renderer:

```yaml
# config/services.yaml — e.g. Markdown
Nowo\TaskBoardBundle\Text\TaskTextRendererInterface:
    alias: App\Tasks\Text\MarkdownTaskTextRenderer
```

The renderer's HTML is printed raw: escape or sanitize what users typed. A plain-text description now shows rendered
with *Edit description* (a `<details>`) for editors; host templates overriding `nowo_task_panel` keep their own markup.

## To 1.18.1

From **1.18.0**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. CSS only: host
rules that shrank the attribute rows or removed the doubled line under them can go.

## To 1.18.0

From **1.17.1**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. No schema change.

- New route `task_import` (POST): hosts that list or secure the bundle's routes by name add it (task write, like
  `task_subtask`).
- Hosts using `ux_icon` import `tabler:help` (the help buttons).
- A host template overriding `nowo_task_subtasks` gets *Import subtasks* only if it includes
  `@NowoTaskBoardBundle/manage/_task_subtask_import.html.twig`; one overriding `manage/import.html.twig` can include
  `@NowoTaskBoardBundle/manage/_import_help.html.twig` (inside `data-controller="nowo-task-board-dialog"`).

## To 1.17.1

Skip 1.17.0 (its container does not compile): `composer update nowo-tech/task-board-bundle`. Nothing else changes.

## To 1.17.0

From **1.16.1**: `composer update nowo-tech/task-board-bundle`. No schema change, nothing to do unless the host wants
its own filter fold on the list view:

```yaml
nowo_task_board:
    templates:
        filters_collapse: '@NowoUiKitBundle/partials/_filters_collapse.html.twig'   # or the host's wrapper
        filters_form_class: 'my-filter-card'
```

With `filters_collapse` set the bundle's "Filters" toggle (and its localStorage state) is not rendered.

## To 1.16.1

From **1.16.0**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. No schema change.

- Host CSS that patched the Gantt sticky column, the list filter checkbox labels, the task attributes on phones or
  the deviation durations can go: `task-board.css` now does it.
- The list filter label rules have no specificity (`:where()`): a host rule of any specificity on those labels wins.
- The people dialog's person options read the name (then the e-mail). To keep e-mails only, pass
  `user_choice_label: 'email'` to `TaskMemberFormType`. The Add member button moved from the add form row to the
  dialog footer (`form="nowo-task-members-add-<task id>"`); a host override of `_task_members_modal.html.twig` should
  follow.

## To 1.16.0

From **1.15.x**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`.

**Schema change (every host):** the task comments table. Generate and run a migration
(`php bin/console doctrine:migrations:diff` then `doctrine:migrations:migrate`), or apply (MySQL; `user` is your
user table):

```sql
CREATE TABLE task_board_task_comments (id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, edited_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, mentions JSON NOT NULL, body LONGTEXT NOT NULL, task_id VARCHAR(36) NOT NULL, author_id INT NOT NULL, INDEX IDX_BC3E8197F675F31B (author_id), INDEX task_board_task_comments_task_idx (task_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E81978DB60186 FOREIGN KEY (task_id) REFERENCES task_board_tasks (id) ON DELETE CASCADE;
ALTER TABLE task_board_task_comments ADD CONSTRAINT FK_BC3E8197F675F31B FOREIGN KEY (author_id) REFERENCES user (id) ON DELETE CASCADE;
```

The demo ships it as `demo/symfony8/migrations/Version20261101120000.php`.

Then check these if they apply to you:

- **Readers comment by default** (`comments.readers_can_comment: true`): set it to `false` to keep comments to the
  people who may edit the task.
- **Who can be @mentioned**: implement `Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface` (the
  people who can read the task) and set `comments.mention_resolver`; the default offers the board's assignable users.
- **Notifications**: listen to `TaskBoardEvents::TASK_MENTION` / `TASK_COMMENT`; the bundle sends nothing itself.
- **Routes**: three new manage routes (`task_comment`, `task_comment_edit`, `task_comment_delete`).
- **Overrides of `_task_history_section.html.twig`** get `comments`, `commentForm`, `commentEditForms`,
  `commentDeletable`, `commentDeleteForm`, `mentionCandidates` and `viewerId`; the new partials are
  `_task_comment.html.twig` and `_task_comment_box.html.twig`.
- **`ux_icon` hosts** import `tabler:message-circle`.
- **Titles and labels** given by code are now cut to their column instead of failing.

## To 1.15.0

From **1.14.x**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`.

**Schema change (every host):** a nullable start date on the tasks (dependencies and time entries already had their
tables). Generate and run a migration (`php bin/console doctrine:migrations:diff` then `doctrine:migrations:migrate`),
or apply (MySQL):

```sql
ALTER TABLE task_board_tasks ADD start_at DATETIME DEFAULT NULL;
```

Existing tasks keep `NULL`: their Gantt bars start on their creation day, as before. The demo ships it as
`demo/symfony8/migrations/Version20261031120000.php`. Seeds or imports that faked `createdAt` to place a bar should set
`Task::setStartAt()` instead.

Then check these if they apply to you:

- **Routes**: four new manage routes (`task_dependency`, `task_dependency_remove`, `task_time`, `task_time_remove`);
  hosts with `tenancy.tenant_paths` get their tenant copies automatically. A host that lists the routes by hand
  (`routes.*`) gets the defaults.
- **Week start**: the Gantt weeks start on Monday (`gantt.week_start`); set `sunday`, or a
  `gantt.week_start_resolver` service for a per-user choice.
- **`ux_icon` hosts** import three more icons: `tabler:hourglass`, `tabler:filter` and `tabler:calendar-event`.
- **Task titles** (and board / column names, link labels) longer than their column are form errors now (`Length`,
  `maxlength`); a refused inline task shows an error flash (`task_board.flash.task_invalid`) instead of a 500.
- **`TaskFormType`** (full form) has one more field, `startAt`; `TaskFormData::$startAt`; `TaskUpdatedEvent` may list
  `startAt`; `TaskChangeType::StartDate`.
- **`TaskListFilterType`** has a `sort` field (display option).
- **Overrides of `manage/gantt.html.twig`** keep working with `timeline.dayKeys` in the days scale (the default);
  draw `timeline.slots` to support the other scales. `TaskGanttItem::$startIndex` / `$endIndex` are columns of the
  scale.
- **Overrides of `manage/list.html.twig`**: `TaskListFilterType` has two more fields (`overEstimate`, `timeColumns`;
  the remaining-children loop prints them) and the list filter controller reads `data-estimate`, `data-spent` and
  `data-over` on the rows.
- **Overrides of `_task_blocks.html.twig`**: the Dependencies section is now `_task_dependencies_section.html.twig`
  and a new block `nowo_task_time` includes `_task_time_section.html.twig`.
- **Theming** (optional): `--nowo-task-gantt-spent`, `--nowo-task-overrun`, `--nowo-task-on-track`,
  `--nowo-task-under`.
- **Custom `TaskBoardManageController` wiring**: three optional constructor arguments at the end
  (`dependencyManager`, `timeLogger`, `weekStartResolver`); without them the dependency and time forms are not shown
  and the week starts on Monday.

## To 1.14.1

From **1.14.0**: `composer update nowo-tech/task-board-bundle`. Bug fixes only (an empty board slug no longer fails; the task detail fields
have an `aria-label`); no schema or configuration change. A host override of `manage/_task_blocks.html.twig` can add
the same `aria-label`s.

## To 1.14.0

From **1.13.x**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`.

**Schema change (every host):** a nullable colour on the tasks. Generate and run a migration
(`php bin/console doctrine:migrations:diff` then `doctrine:migrations:migrate`), or apply (MySQL):

```sql
ALTER TABLE task_board_tasks ADD color VARCHAR(7) DEFAULT NULL;
```

Existing tasks keep `NULL` (no colour: the Gantt keeps the priority colours). The demo ships it as
`demo/symfony8/migrations/Version20261030120000.php`.

Then check these if they apply to you:

- **`ux_icon` hosts** import one more icon: `tabler:palette`.
- **Overrides of `_add_task_modal.html.twig`, `_task_members_modal.html.twig`, `_task_priority_modal.html.twig` or
  `_task_links_modal.html.twig`** that copied the Bootstrap `.modal` markup keep working (the controllers keep the
  legacy branch); the bundle's dialogs are now `<dialog class="nowo-task-dialog nowo-task-dialog--native">` and use
  no Bootstrap layout utilities. CSS written for the old `.modal-*` structure of these dialogs no longer applies.
- **Own task form templates:** `TaskFormType` (full form) has two more fields, `color` (hidden) and `customColor`
  (unmapped colour input); render them with `_colour_picker.html.twig` or let the "remaining children" loop print them.
- **`TaskUpdatedEvent` listeners** may see `color` in `getChangedFields()`.
- **Theming:** no new token; the task colour reaches CSS as `--nowo-task-bar-color` (Gantt bar) and
  `--nowo-task-card-color` (card edge) through `data-nowo-task-vars`.

## To 1.13.1

From **1.13.0**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. Optional: set
`--nowo-task-overlay` (and `--nowo-task-overlay-blur`) to your modals' backdrop.

## To 1.13.0

From **1.12.x**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. No schema change.

- Overrides of `_add_column_modal.html.twig` / `_edit_column_modal.html.twig` that copied the Bootstrap `.modal`
  markup keep working; the new dialogs are `<dialog class="nowo-task-dialog nowo-task-dialog--native">`.
- For foldable list groups, register UiKit's `collapse-panel` and `disclosure-icon` Stimulus peers in the host app.
- `ux_icon` hosts import one more icon: `tabler:x`.

## To 1.12.0

From **1.11.x**: `composer update nowo-tech/task-board-bundle`, then `php bin/console assets:install`. No schema change.

- **Look**: the task detail, board cards and list changed layout (ClickUp-like) and use the bundle's own classes.
  A host that styled the old Bootstrap structure (`.card`, `.list-group-item`, `.row` inside the task page) moves its
  rules to the new classes, or themes through the `--nowo-task-*` tokens (USAGE, "Theming with design tokens").
- **Icons**: with `nowo_ui_kit.icon_set: ux_icon`, import the Tabler icons listed in USAGE ("Icons"); with the Tabler
  webfont nothing changes.
- **Overridden templates**: the blocks `nowo_task_panel`, `nowo_task_toolbar`, `nowo_task_subtasks`,
  `nowo_task_relations` keep their names; their markup changed, so re-check overrides that copied it. A board / list
  template override that wants the inline *+ New task* includes `@NowoTaskBoardBundle/manage/_inline_task_add.html.twig`
  with `inlineTaskForms[column.id]`.
- **Tests**: assertions on the old classes (`fw-semibold text-truncate`, `text-decoration-line-through`, `mt-2` on the
  progress bar) now read the new ones (`nowo-task-link__label`, `nowo-task-activity__old`, `nowo-task-progress`).

## To 1.11.1

From **1.11.0**: no change needed.

## To 1.11.0

From **1.10.x**:

```bash
composer update nowo-tech/task-board-bundle
```

**Schema change (every host):** a nullable column on the board columns and a new table for the column sets. Generate
and run a migration (`php bin/console doctrine:migrations:diff` then `doctrine:migrations:migrate`), or apply the
equivalent of (MySQL; `created_by_id` has the type of your `user_class` id and references its table):

```sql
ALTER TABLE task_board_columns ADD icon VARCHAR(64) DEFAULT NULL;
CREATE TABLE task_board_column_sets (
    id VARCHAR(36) NOT NULL,
    created_by_id INT DEFAULT NULL,
    name VARCHAR(128) NOT NULL,
    scope_key VARCHAR(191) DEFAULT NULL,
    items JSON NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_task_board_column_sets_scope (scope_key),
    INDEX IDX_TASK_BOARD_COLUMN_SET_CREATOR (created_by_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_TASK_BOARD_COLUMN_SET_CREATOR FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
```

Existing columns keep `NULL` (no icon). The demo ships it as `demo/symfony8/migrations/Version20261029120000.php`.

Then check these if they apply to you (see [Columns, column sets and the task panel](USAGE.md#columns-column-sets-and-the-task-panel-since-111)):

- **Column icons with `ux_icon`:** the icons of `board.column_icons` are rendered with `ux_icon('tabler:<name>')`;
  import the ones you offer (`bin/console ux:icons:import tabler:bug …`) or set your own list; `[]` hides the field.
- **Column sets are scoped by tenant by default:** with `tenancy`, a set saved on a tenant's board is only offered on
  that tenant's boards. To share the sets across tenants, listen to `TaskBoardEvents::COLUMN_SET_QUERY` and call
  `setScopeKey(null)`. Who may delete a set: its creator or a `security.manage_roles` role, unless a
  `COLUMN_SET_ACCESS_CHECK` listener decides.
- **Template overrides of `manage/task.html.twig`:** the page now `{% use %}`s `manage/_task_blocks.html.twig`; the
  blocks keep their names (`nowo_task_panel`, `nowo_task_toolbar`, `nowo_task_subtasks`, `nowo_task_relations`, plus
  `nowo_task_body` around them), so overrides in a template extending `task.html.twig` keep working. A full copy of
  the old page keeps working too, but does not get the panel's `_target_path` on its forms.
- **Overrides of `_board_column.html.twig`, `gantt.html.twig` or the task page with inline `style=""`:** the bundle no
  longer prints inline styles; the colours and Gantt positions come from `data-nowo-task-vars`, applied by
  `task-board.js`. A page that does not load `task-board.js` shows no column colour bar and unpositioned Gantt bars.
- **Own `ColumnSetRepositoryInterface`:** new interface; the bundle aliases it to the Doctrine implementation.
- **Controllers or services built by hand** (tests): the new constructor arguments are optional and trailing; the
  task write actions return `Response`.

## To 1.10.1

From **1.10.0**: no change needed.

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/task-board-bundle
```

**Schema change (every host):** the entities gained nullable columns. Generate and run a migration
(`php bin/console doctrine:migrations:diff` then `doctrine:migrations:migrate`), or apply the equivalent of (MySQL,
default `table_prefix`):

```sql
ALTER TABLE task_board_boards ADD tenant_key VARCHAR(191) DEFAULT NULL;
CREATE INDEX idx_task_board_boards_tenant ON task_board_boards (tenant_key);
ALTER TABLE task_board_task_links ADD object_type VARCHAR(64) DEFAULT NULL, ADD object_id VARCHAR(191) DEFAULT NULL;
CREATE INDEX idx_task_board_task_links_object ON task_board_task_links (object_type, object_id);
```

Existing rows keep `NULL` (boards outside any tenant, ordinary links), so no data migration is needed. The demo ships
it as `demo/symfony8/migrations/Version20261028120000.php`.

Nothing else is required: tenancy, the assignee provider, the board options and the object link resolvers are opt-in
(see [Multi-tenant hosts](USAGE.md#multi-tenant-hosts-since-110)). Check these if they apply to you:

- **Own repository implementations:** `TaskRepositoryInterface` gained `findByHostObject()` and
  `TaskBoardRepositoryInterface` gained `findActiveByTenant()` / `findOneByTenant()`.
- **Template overrides of `manage/task.html.twig`:** the subtask counter is now recursive (`subtaskTotal` /
  `subtaskDone` count the whole subtree) and the controller passes `subtaskProgress` and `taskTree`; the nested
  levels render through `_subtask_tree.html.twig`. The board cards and columns moved to `_task_card.html.twig` /
  `_board_column.html.twig`. Prefer overriding the new blocks over copying pages.
- **Controllers or services built by hand** (tests): the new constructor arguments are optional and trailing.
- **Security — read-only board access (behaviour change):** board writes (columns, new tasks, import) now require
  `TaskBoardResourceAccessChecker::canEditBoard()` instead of view access. Users who can view a board through the
  default rules (creator, team member, `security.manage_roles`, or `security.resource_scoping: false`) keep writing as
  before. A `BOARD_ACCESS_CHECK` listener that should grant read-only access calls `$event->grant()` and
  `$event->markReadOnly()`: those users now get 403 on every board and task write (before 1.10 they could change
  columns, create tasks and import). If you relied on viewers being able to write, do not mark them read-only. To map
  a host permission (e.g. Beacon `project.tasks.view` / `project.tasks.manage`), grant on view and call
  `markReadOnly()` when the manage permission is missing.
- **Template overrides that include `_page_header.html.twig`:** `headerActions` is now a list of
  `{href, label, icon?, class?}` printed with auto-escaping (no `|raw`). An HTML string still passed by an older
  override is printed escaped (visible as text): convert it to the list.
- **Enabling tenancy:** the tenant-less routes (`/tools/task-board/...`) answer 404 while it is on, and the router is
  decorated by `Nowo\TaskBoardBundle\Routing\TenantPathUrlGenerator`. Existing boards have no tenant key: set
  `tenant_key` on the boards a tenant should keep, otherwise `single_board` creates a new board on the first visit.

## To 1.9.0

From **1.8.4**:

```bash
composer update nowo-tech/task-board-bundle
```

- **Template overrides (REQ-TWIG-005):** `manage/list.html.twig`, `manage/task.html.twig`, `_task_links_modal`, `_task_members_modal` and `_task_priority_modal` no longer contain raw `<form>` / `<input>` / `<select>` markup. The controller now always passes `filterForm` (list) and `advanceForm`, `linkRemoveForm`, `memberRemoveForms`, `priorityForm` (task page). If you override those templates, render them with `form_start` / child loop / `form_end` (see [USAGE](USAGE.md#forms-req-twig-005)). Routes, posted field names (`_token`, `priority`, `action`, `columnId`) and CSRF ids are unchanged, so old overrides keep posting correctly until migrated. `updatePriority` now loads the task before validating the CSRF token (an unknown task answers 404 before a bad token answers 403).

## To 1.8.4

From **1.8.3**:

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.8.3

From **1.8.2**:

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.8.2

From **1.8.1**:

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.8.0

From **1.7.2** — task form works without host Tiptap config (prepended task profile)

```bash
composer update nowo-tech/task-board-bundle
```

- With `nowo-tech/tiptap-editor-bundle` installed, the Tiptap profile `task` is now seeded by the bundle. If you only defined `nowo_tiptap_editor.profiles.task` to make the task form work, you may remove it; keeping it overrides the seeded profile.

## To 1.7.2

From **1.7.1** — task detail page renders again (loggedMinutes, tag history)

```bash
composer update nowo-tech/task-board-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.7.1

From **1.7.0** — CONFIGURATION.md corrected for task-member board visibility.

```bash
composer update nowo-tech/task-board-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.7.0

From **1.6.0** — fixes - complete translations, configurable route names in templates, column ordering, full test coverage.

```bash
composer update nowo-tech/task-board-bundle
```

- **List and Gantt views now include completed tasks** (tasks in the last column). Use the list's column filter to hide them.
- **Task positions are renumbered on drag & drop:** `TaskManager::move($task, $columnId, $position, $user)` treats `$position` as the index inside the target column (clamped to its size) and rewrites the column's positions to `0..n-1`, saving siblings whose position changed. Code relying on arbitrary/gapped positions passed to `move()` should pass an index instead.
- **Overridden templates:** if you renamed `routes.<key>.name`, use `path(nowo_task_board_route('<key>'), …)` in your own template overrides instead of the default `nowo_task_board_*` names.
- **Column choices:** boards with duplicate column names now show `Name (2)`, `Name (3)`… in the task form's column select.
- **Deprecated:** remove `nowo_task_board.firewall` from your configuration (it never had an effect).
- `TaskBoardTwigExtension` gained an optional third constructor argument `$routes`; manual instantiations keep working.

## To 1.6.0

From **1.5.8** — security: per-board authorization, safe link URLs and column colours, optional form types.

```bash
composer update nowo-tech/task-board-bundle
```

- **Per-board authorization (security fix):** boards/tasks are now restricted to the board creator, team members, task members and `security.manage_roles` (default `[ROLE_ADMIN]`). Users who previously opened other people's boards now get 403 and no longer see them on the index. Add team memberships, grant `manage_roles`, or listen to `TaskBoardEvents::BOARD_ACCESS_CHECK`; `security.resource_scoping: false` restores the old behaviour (not recommended).
- Task links must be `http(s)` URLs and column colours hex colours; existing non-conforming values are not rendered as links/styles.

## To 1.5.8

From **1.5.7** — Doctrine ORM `SortDirection` mapping.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

- No breaking changes for hosts already on Doctrine ORM 3. The bundle now requires `doctrine/orm` `^3.7` (ORM 2.x is no longer supported); mappings use the `SortDirection` enum (native on PHP 8.6, polyfilled by `symfony/polyfill-php86` on older PHP).

## To 1.5.7

From **1.5.6** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

_(empty)_
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## To 1.5.4

Review Flex recipe `security_nowo_task_board.yaml` after update.

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.5.3

From **1.5.2** — No application upgrade steps (release notes only; recipe files ship in **1.5.4**).

## To 1.5.2

From **1.5.1** — No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/task-board-bundle
```

## To 1.5.0

From **1.4.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## 1.4.0 (2026-08-03)

Minor release: REQ-UI-002 `security.allow_unauthenticated` + `AllowAllTaskBoardAccessChecker`, and SecurityBundle compile-time guard. CI/demo no longer path-mount sibling optional bundles.

### Install / update

```bash
composer require nowo-tech/task-board-bundle:^1.4
php bin/console cache:clear
```

### REQ-UI-002 allow_unauthenticated

| Topic | Before | 1.4.0 |
| --- | --- | --- |
| Default | Roles via `access_roles` / custom `access_checker` | Same, plus explicit `allow_unauthenticated: false` |
| Apps without SecurityBundle | Could boot if DI happened to work | Boot fails with `LogicException` unless `allow_unauthenticated: true` |

**Demos / trusted local kernels** without SecurityBundle:

```yaml
nowo_task_board:
    security:
        allow_unauthenticated: true   # never in production
```

**Production** (recommended): keep `allow_unauthenticated: false`, ensure SecurityBundle is installed, and grant at least one of `access_roles` (default `[ROLE_USER]`; hosts may tighten to `ROLE_ADMIN`).

### Breaking changes

Apps that load the manage UI without SecurityBundle must either install/configure SecurityBundle or set `allow_unauthenticated: true` for non-production use.

## 1.3.1 (2026-07-30)

### CSRF on kanban / bare POSTs (REQ-SEC-005)

Session-authenticated POSTs that do **not** go through Symfony Forms now require a CSRF `_token`. If you override manage Twig or call these endpoints from custom JS, send the token:

| Action | Token id (`csrf_token(...)`) |
|--------|------------------------------|
| Column reorder | `task_board_column_reorder` |
| Task move (drag) | `task_board_task_move` |
| Task advance / column jump | `task_board_task_advance` |
| Update priority | `task_board_task_priority` |
| Remove link | `task_board_task_link_remove` |
| Remove member | `task_board_task_member_remove_{memberId}` |

Board Stimulus values: `data-nowo-task-board-reorder-csrf-token-value` and `data-nowo-task-board-move-csrf-token-value`. Rebuild/install assets if you fork the bundle (`pnpm run build` + `assets:install`).

## 1.3.0 (2026-07-30)

Host layout / CSS stack integration without forking manage pages (REQ-UI-001).

```bash
composer require nowo-tech/task-board-bundle:^1.3.0
php bin/console cache:clear
```

### Layout integration (REQ-UI-001)

Manage pages no longer hard-extend `@NowoTaskBoardBundle/layout.html.twig`. They extend the Twig global `nowo_task_board_layout` (`templates.layout`).

To use your project chrome without forking pages:

```yaml
nowo_task_board:
    templates:
        layout: 'base.html.twig'   # or a one-file bridge
        css_framework: bootstrap5  # default tabler; or custom | …
```

`templates.css_framework` (Twig global `nowo_task_board_css_framework`) defaults to **`tabler`**. With `custom`, style semantic `nowo-ui-*` classes from the host.

If you overrode manage Twig templates, switch `{% extends '@NowoTaskBoardBundle/layout.html.twig' %}` to `{% extends nowo_task_board_layout %}` and call `{{ parent() }}` in `stylesheets` / `javascripts`. See [CONFIGURATION.md — Layout integration](CONFIGURATION.md#layout-integration-req-ui-001).

## 1.2.4 (2026-07-29)

Patch release. **No breaking changes** for typical bundle consumers.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

No configuration or schema changes required for existing boards.

### Notes

- FrankenPHP worker mode declared friendly; demo uses PHP **8.5** and path `/tools/task-board`.
- Layout no longer links TimeTrack when the optional bundle is absent (fixes demo HTTP 500).
- Contributors: PHPStan stubs + `phpstan-frankenphp`; baseline removed — run `composer install` before `make phpstan`.
- Entity metadata cleanup (`repositoryClass` removed; host user FQCN `\App\Entity\User::class`) — if you override user class via bundle config, keep your existing mapping.

## 1.2.3 (2026-07-16)

Patch release. **No breaking changes** for bundle consumers.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

No configuration or schema changes required.

### Notes

- Updated translations (import UI in all locales; EN German leftovers fixed). Clear the translation cache if you override `NowoTaskBoardBundle` strings.
- Maintainer-only: REQ-GIT-001 git hygiene (hooks, CI job, `docs/GITHUB_CI.md`) and Code of Conduct — see [CONTRIBUTING.md](CONTRIBUTING.md).

## 1.2.2 (2026-07-13)

Patch release. **No breaking changes** and **no runtime changes** for bundle consumers.

```bash
composer update nowo-tech/task-board-bundle
```

No configuration or schema changes required.

### Notes

- Maintainer-only: improved `make test-coverage` output, Docker coverage volume, and `.cursorignore` (see [CHANGELOG](CHANGELOG.md#122---2026-07-13)).

## 1.2.1 (2026-07-08)

Patch release. **No breaking changes** and **no runtime changes** for bundle consumers.

```bash
composer update nowo-tech/task-board-bundle
```

No configuration, schema, or cache steps required unless you want the updated documentation in your vendor copy.

### Notes

- New **export guides** for task import: [docs/import/README.md](import/README.md) (ClickUp, Jira, Trello).
- **GitHub Spec Kit** (`.specify/`, `docs/SPEC-KIT.md`) is maintainer tooling for this repository only.

## 1.2.0 (2026-07-07)

Minor release. **No breaking changes** for consumers.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

No configuration or schema changes required. The import feature uses the existing `task_board_task_links.external_id` column.

### New: task import

- Manage UI: open a board → **Import tasks** (`/tools/task-board/board/{boardId}/import`).
- CLI: `php bin/console nowo:task-board:import <board-uuid> /path/to/export.csv --source=clickup_csv`

Supported `--source` values: `clickup_csv`, `clickup_json`, `jira_csv`, `trello_json`.

To map assignee emails from exports to your User entity, register a service implementing `TaskImportUserResolverInterface` and alias it:

```yaml
services:
    App\TaskBoard\ImportUserResolver: ~

    Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface:
        alias: App\TaskBoard\ImportUserResolver
```

See [USAGE.md](USAGE.md) for import options (create missing columns, skip duplicates).

Export step-by-step guides: [import/README.md](import/README.md).

## 1.1.1 (2026-07-07)

Patch release. **No breaking changes** for consumers.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
php bin/console assets:install
```

No configuration or schema changes required.

### Notes

- Fixes Symfony DI for placeholder Doctrine repositories when using path-mounted bundles (e.g. combined TimeTrack demo).
- Corrects the `nowo_task_board` asset package base path; run `assets:install` so CSS/JS load correctly.
- Manage form labels now use the `NowoTaskBoardBundle` translation domain consistently.

## 1.1.0 (2026-07-06)

Minor release. TaskBoard is now **standalone** — TimeTrack is no longer pulled in automatically.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

### If you use time tracking

Ensure TimeTrack remains in your project (it is no longer a transitive dependency of TaskBoard):

```bash
composer require nowo-tech/time-track-bundle
```

No configuration changes are needed if TimeTrack was already installed and wired:

```yaml
# config/packages/nowo_time_track.yaml
nowo_time_track:
    task_provider: nowo_task_board.task_provider
    team_context_provider: nowo_task_board.team_context_provider
```

### If you only use TaskBoard

No action required. You can remove TimeTrack if it was only installed as a dependency of TaskBoard and you do not use timers.

### Notes

- Bridge services and `TimeSpentAggregatorListener` register at runtime only when TimeTrack classes are present.
- No database schema changes in this release.

## 1.0.1 (2026-07-06)

Patch release. **No breaking changes** for consumers.

```bash
composer update nowo-tech/task-board-bundle
php bin/console cache:clear
```

No configuration or schema changes required.

### Notes

- Internal fix: `DoctrineOrmTaskLinkRepository` DI (no action needed unless you extend that class).
- Symfony 8.x requires PHP ≥ 8.4 and `doctrine/doctrine-bundle` ^3.0 (unchanged from 1.0.0).

## 1.0.0 (2026-07-06)

First stable release. No upgrade steps when installing for the first time.

### Requirements

- **PHP:** >= 8.2, < 8.6
- **Symfony:** ^7.4 || ^8.0
- **Doctrine Bundle:** ^2.10 (Symfony 7.x) or ^3.0 (Symfony 8.x)
- **Doctrine ORM:** ^2.15 || ^3.0
- **TimeTrack:** `nowo-tech/time-track-bundle` ^1.0 (required in 1.0.x; optional from 1.1.0)

### Install

```bash
composer require nowo-tech/task-board-bundle
# optional from 1.1.0; required in 1.0.x:
composer require nowo-tech/time-track-bundle
php bin/console assets:install
php bin/console doctrine:schema:update --force
# or create a migration
```

Configure `user_class` and wire TimeTrack providers (when using TimeTrack):

```yaml
# config/packages/nowo_time_track.yaml
nowo_time_track:
    task_provider: nowo_task_board.task_provider
    team_context_provider: nowo_task_board.team_context_provider
```

Secure manage routes (default `/tools/task-board`):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/tools/task-board, roles: ROLE_USER }
```

See [INSTALLATION.md](INSTALLATION.md) for the full setup.

## Future 1.x releases

Breaking or other notable changes will be documented here.
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `task_board` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.
