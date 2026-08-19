# Upgrading

## To 1.5.1

From **1.5.0** — YAML services_timetrack indentation fix only. No host migration.

```bash
composer update nowo-tech/task-board-bundle
```


## Table of contents

- [Unreleased](#unreleased)
- [To 1.5.4](#to-154)
- [To 1.5.3](#to-153)
- [To 1.5.2](#to-152)
- [To 1.5.0](#to-150)
- [1.4.0 (2026-08-03)](#140-2026-08-03)
  - [REQ-UI-002 allow_unauthenticated](#req-ui-002-allow_unauthenticated)
- [1.3.1 (2026-07-30)](#131-2026-07-30)
  - [CSRF on kanban / bare POSTs (REQ-SEC-005)](#csrf-on-kanban--bare-posts-req-sec-005)
- [1.3.0 (2026-07-30)](#130-2026-07-30)
  - [Layout integration (REQ-UI-001)](#layout-integration-req-ui-001)
- [1.2.4 (2026-07-29)](#124-2026-07-29)
  - [Notes](#notes)
- [1.2.3 (2026-07-16)](#123-2026-07-16)
  - [Notes](#notes-1)
- [1.2.2 (2026-07-13)](#122-2026-07-13)
  - [Notes](#notes)
- [1.2.1 (2026-07-08)](#121-2026-07-08)
  - [Notes](#notes)
- [1.2.0 (2026-07-07)](#120-2026-07-07)
  - [New: task import](#new-task-import)
- [1.1.1 (2026-07-07)](#111-2026-07-07)
  - [Notes](#notes)
- [1.1.0 (2026-07-06)](#110-2026-07-06)
  - [If you use time tracking](#if-you-use-time-tracking)
  - [If you only use TaskBoard](#if-you-only-use-taskboard)
  - [Notes](#notes)
- [1.0.1 (2026-07-06)](#101-2026-07-06)
  - [Notes](#notes)
- [1.0.0 (2026-07-06)](#100-2026-07-06)
  - [Requirements](#requirements)
  - [Install](#install)
- [Future 1.x releases](#future-1x-releases)

This document describes how to upgrade between versions of TaskBoard Bundle.

## Unreleased

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
