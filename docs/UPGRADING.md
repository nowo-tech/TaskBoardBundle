# Upgrading

## Table of contents

- [To 1.5.1](#to-151)
- [From 1.5.5 to 1.5.6](#from-155-to-156)
- [Unreleased](#unreleased)
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
