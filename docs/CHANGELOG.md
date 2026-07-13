# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.2] - 2026-07-13

### Changed

- **Development** — `make test-coverage` writes `coverage-php.txt` and prints the PHP Lines % via `.scripts/php-coverage-percent.sh` (REQ-TEST-008 contract).
- **Docker** — named volume `coverage-data` for `/app/coverage` in `docker-compose.yml` (avoids host permission issues).
- **`.gitignore`** — ignore `.cursor/sandbox.json` and `coverage-php.txt`.

### Added

- **`.cursorignore`** — excludes vendor, caches, build outputs, and heavy binaries from Cursor indexing.
- **PHP CS Fixer** — exclude auto-generated `demo/symfony8/config/reference.php` from the finder.

## [1.2.1] - 2026-07-08

### Added

- **Import export guides** — English manuals under `docs/import/` for exporting boards from [ClickUp](import/clickup.md), [Jira](import/jira.md), and [Trello](import/trello.md) (field mapping, CLI examples, and import tips).
- **GitHub Spec Kit** — `.specify/` workflows, templates, and [SPEC-KIT.md](SPEC-KIT.md) for spec-driven development in this repository (maintainer tooling; no runtime change for bundle consumers).

### Changed

- **Documentation** — README, USAGE, CONFIGURATION, and SPEC-DRIVEN-DEVELOPMENT link to the new import guides and Spec Kit docs.

## [1.2.0] - 2026-07-07

### Added

- **Task import** — upload CSV/JSON exports from **ClickUp**, **Jira**, and **Trello** into an existing board (`board_import` route, manage UI, and `nowo:task-board:import` console command).
- **Extensible importers** — `TaskImporterInterface` with tagged services (`nowo_task_board.task_importer`) for adding more sources.
- **ClickUp** — CSV and JSON importers; maps status, priority, description, assignee, due date, time estimate, tags, parent ID, and task URL; auto-creates missing status columns.
- **Jira CSV** — maps Summary, Status, Priority, Description, Assignee, Due Date, and Issue key.
- **Trello JSON** — maps board export cards (name, list/status, description, due date, labels, short URL).
- **Idempotent re-import** — skips tasks whose external ID was already imported (stored on `TaskLink.externalId`).
- **Assignee mapping** — optional `TaskImportUserResolverInterface` service alias to resolve import emails to application users.
- **Translations** — import UI strings in English and Spanish.

## [1.1.1] - 2026-07-07

### Fixed

- **Doctrine repository stubs** — inject `EntityManagerInterface` in `DoctrineOrmTaskDependencyRepository`, `DoctrineOrmTaskDocumentRepository`, `DoctrineOrmTaskMemberRepository`, and `DoctrineOrmTaskTimeEntryRepository` so Symfony can wire services registered by `TaskBoardExtension` (fixes DI errors when running demos with path-mounted bundles).
- **Asset package path** — correct `nowo_task_board` base path to `/bundles/taskboard` (Symfony `assets:install` output for `TaskBoardBundle`).
- **Form translations** — set `translation_domain` to `TaskBoardBundle::TRANSLATION_DOMAIN` on all manage form types so labels resolve from `NowoTaskBoardBundle` translations.

## [1.1.0] - 2026-07-06

### Changed

- **TimeTrack is optional** — `nowo-tech/time-track-bundle` is no longer a runtime dependency. Bridge services (`TaskBoardTaskProvider`, `TaskBoardTeamContextProvider`) and `TimeSpentAggregatorListener` load only when TimeTrack is installed (`interface_exists` check). Install TimeTrack explicitly and wire `nowo_task_board.task_provider` / `nowo_task_board.team_context_provider` for timer integration.
- **`composer.json`** — TimeTrack moved to `require-dev`; added `suggest` entry; removed local path repository from the published manifest.
- **CI** — TimeTrack installed via `require-dev` in `.github/ci/composer-install*.sh` (path repo clone unchanged).
- **Documentation** — README, INSTALLATION, CONFIGURATION, USAGE, SECURITY, UPGRADING, and Flex `post-install.txt` describe TimeTrack as optional.
- **Development** — Makefile generates gitignored `composer.dev.json` when `../TimeTrackBundle` is present (path repo for local integration tests).

## [1.0.1] - 2026-07-06

### Fixed

- **`DoctrineOrmTaskLinkRepository`** — inject `EntityManagerInterface` so Symfony can autowire the repository.
- **CI** — install dependencies without `composer.lock` via `.github/ci/composer-install.sh` (jq + full `composer update`).
- **CI** — clone TimeTrackBundle by tag `v1.0.0` (matches `^1.0` constraint).
- **CI** — stop PHP CS Fixer from reformatting `composer.json` (exclude from finder; code-style job commits `src/` and `tests/` only).

### Changed

- **CI matrix** — Symfony **7.4**, **8.0**, and **8.1** (PHP 8.2–8.5; Symfony 8 requires PHP ≥ 8.4).

## [1.0.0] - 2026-07-06

First stable release of **TaskBoard Bundle**.

### Added

- **Task boards** — boards with configurable columns, slug, description, optional team, and archive support.
- **Tasks** — kanban columns, priorities, statuses, subtasks, assignees, due dates, and change history.
- **Teams** — teams and team members with manager/member roles.
- **Task links** — external links (including GitLab merge request URL parsing).
- **Task dependencies** — blocking relationships for Gantt view.
- **Web UI** — manage routes at `/tools/task-board` (index, kanban board, list, Gantt, task detail).
- **TimeTrack integration** — `TaskBoardTaskProvider` and `TaskBoardTeamContextProvider` bridges; `TimeSpentAggregatorListener` updates `task.total_time_seconds` on timer stop.
- **Access control** — `TaskBoardAccessCheckerInterface`, `TaskAccessGuard`, and extensibility events (`BoardListQueryEvent`, `TaskAccessCheckEvent`, etc.).
- **Configuration** — `user_class`, `table_prefix`, routes, templates, security roles, and optional `team_membership_resolver`.
- **Persistence** — Doctrine ORM entities and repositories (`task_board_*` tables).
- **TypeScript / Stimulus** — Vite + pnpm assets (`task_board.js`, package `nowo_task_board`).
- **Translations** — `NowoTaskBoardBundle` domain (EN, ES, DE, FR, IT, NL, PT).
- **Symfony Flex recipe** — `1.0.0` with default config and routes.
- **Demo** — Symfony 8.1 + FrankenPHP + MySQL (`demo/symfony8/`).
- **Tooling** — PHPUnit, Vitest, PHP-CS-Fixer, Rector, PHPStan, GitHub Actions CI.

### Requirements

- PHP >= 8.2, < 8.6
- Symfony ^7.4 || ^8.0
- Doctrine ORM ^2.15 || ^3.0
- `nowo-tech/time-track-bundle` ^1.0 (required in 1.0.x; optional from 1.1.0)

[Unreleased]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.2...HEAD
[1.2.2]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.0.0
