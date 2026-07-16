# Upgrading

This document describes how to upgrade between versions of TaskBoard Bundle.

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
