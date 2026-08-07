# Changelog

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.5.1] - 2026-08-07](#151---2026-08-07)
- [[1.5.0] - 2026-08-04](#150---2026-08-04)
- [[1.4.0] - 2026-08-03](#140---2026-08-03)
  - [Added](#added)
  - [Changed](#changed)
  - [Compatibility](#compatibility)
- [[1.3.1] - 2026-07-30](#131---2026-07-30)
  - [Security](#security)
- [[1.3.0] - 2026-07-30](#130---2026-07-30)
  - [Added](#added-1)
- [[1.2.4] - 2026-07-29](#124---2026-07-29)
  - [Added](#added-1)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.2.3] - 2026-07-16](#123---2026-07-16)
  - [Added](#added-1)
  - [Fixed](#fixed-1)
  - [Changed](#changed-1)
- [[1.2.2] - 2026-07-13](#122---2026-07-13)
  - [Changed](#changed)
  - [Added](#added)
- [[1.2.1] - 2026-07-08](#121---2026-07-08)
  - [Added](#added)
  - [Changed](#changed)
- [[1.2.0] - 2026-07-07](#120---2026-07-07)
  - [Added](#added)
- [[1.1.1] - 2026-07-07](#111---2026-07-07)
  - [Fixed](#fixed)
- [[1.1.0] - 2026-07-06](#110---2026-07-06)
  - [Changed](#changed)
- [[1.0.1] - 2026-07-06](#101---2026-07-06)
  - [Fixed](#fixed)
  - [Changed](#changed)
- [[1.0.0] - 2026-07-06](#100---2026-07-06)
  - [Added](#added)
  - [Requirements](#requirements)

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.1] - 2026-08-07

### Fixed

- **CI:** correct indentation in `src/Resources/config/services_timetrack.yaml` (YAML parse error broke container boot / PHPUnit).

[1.5.1]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.1


## [1.5.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `task_board` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Requires `nowo-tech/ui-kit-bundle` `^1.4`. Layout/manage Twig load `asset('css/nowo-ui.css', 'nowo_ui_kit')` and import `@NowoUiKitBundle/macros/ui.html.twig` (domain macros `_task_member_label` / `_task_priority_tag` unchanged). Extension seeds `nowo_ui_kit` from `templates.css_framework` when the host has not configured UiKit. Demo registers `NowoUiKitBundle` + `nowo_ui_kit.yaml`.

[1.5.0]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-08-03

### Added

- **REQ-UI-002** — `security.allow_unauthenticated` (default `false`) and `AllowAllTaskBoardAccessChecker` for trusted demos/dev only.
- Compile-time guard: when `allow_unauthenticated` is `false`, `symfony/security-bundle` is required (`LogicException` otherwise).
- GitHub hygiene workflows: Dependabot, stale issues/PRs (`actions/stale` v11), semantic PR title lint, Copilot instructions.

### Changed

- CI / local Compose no longer clone or path-mount sibling TimeTrack / TagInput / TipTap repos; TimeTrack comes from Packagist (`^1.0`).
- Demo `composer.json` pins `nowo-tech/twig-inspector-bundle` to `^1.0`.
- Docs: CONFIGURATION / SECURITY document `allow_unauthenticated` and the SecurityBundle requirement.

### Compatibility

- PHP `>=8.2`, `<8.6`; Symfony `^7.4 || ^8.0`.
- Manage UI with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos).

## [1.3.1] - 2026-07-30

### Security

- **REQ-SEC-005** — CSRF on bare manage POSTs that previously lacked tokens: column reorder, task move (kanban drag), task advance, priority update, remove link, remove member. Invalid/missing `_token` → access denied (fail-closed). Bundle Twig and board Stimulus `fetch` now send matching tokens.

## [1.3.0] - 2026-07-30

### Added

- **REQ-UI-001 layout wiring** — `templates.layout` is exposed as Twig global `nowo_task_board_layout` via `TaskBoardTwigExtension`. Manage pages `{% extends nowo_task_board_layout %}` and stack CSS/JS with `{{ parent() }}`. Demo `layout.html.twig` keeps Tabler CDN; hosts set `templates.layout` to the project layout or a bridge (`layout_integrate_host.html.twig`). Documented in CONFIGURATION.md / USAGE.md / UPGRADING.md.
- **REQ-UI-001 `templates.css_framework`** — enum (`tabler` default, matching demo CDN; also `bootstrap5` / `bootstrap` / `bootstrap4` / `tailwind` / `foundation` / `custom` / `none`). Container parameter `nowo_task_board.templates.css_framework` and Twig global `nowo_task_board_css_framework`. Invalid values rejected.

## [1.2.4] - 2026-07-29

### Added

- FrankenPHP-friendly banner + `docs/DEMO-FRANKENPHP.md`; `make demo-smoke` / `verify` + `.github/workflows/demo-smoke.yml` (REQ-DEMO-008/002, DOCS-017, TEST-011).
- `make validate-translations`, `make check-open-prs`, `make down-dev` (REQ-MAKE-004 / REL-003 / MAKE-007).
- PHPStan stubs for optional TipTap / TagInput form types; empty `ignoreErrors: []` (REQ-CS-006).
- **REQ-CS-005:** `nowo-tech/phpstan-frankenphp` in `require-dev` with classic + worker rulesets.
- GitHub About Website + Topics (REQ-DOCS-018).

### Changed

- `demo/symfony8` image: `dunglas/frankenphp:1-php8.5-bookworm`; `FRANKENPHP_MODE=worker` (REQ-DEMO-010).
- PHPUnit / CI: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- Composer keywords: `php`, `frankenphp` (REQ-PKG-004).
- README Documentation order; TOC on long docs (REQ-DOCS-002 / DOCS-005).
- Entities: drop unused `repositoryClass`; host user via `\App\Entity\User::class`; `getCreatedAt()` where needed.
- Demo `access_control` path aligned to `/tools/task-board`.
- Removed `phpstan-baseline.neon` (findings fixed or stubbed).

### Fixed

- TaskGanttBuilder null-safe date range for PHPStan level 8.
- Layout: do not link TimeTrack route when TimeTrack is not installed (demo smoke HTTP 500).
- Demo: register DataFixtures services; recreate schema on `make up`.

## [1.2.3] - 2026-07-16

### Added

- **REQ-GIT-001** — reject Cursor `Co-authored-by` trailers: `.scripts/check-no-cursor-coauthor.sh`, `.githooks/commit-msg`, CI job `git-hygiene`, and `make setup-hooks` / `make check-no-cursor-coauthor` / `make strip-cursor-coauthor-from-history`.
- **Code of Conduct** — [Contributor Covenant](../CODE_OF_CONDUCT.md); linked from README and CONTRIBUTING.
- **CI docs** — [GITHUB_CI.md](GITHUB_CI.md) documents the no-Cursor-co-author audit for nowo-tech bundles.
- **Translations** — import UI strings for DE, ES, FR, IT, NL, and PT (previously only EN/ES had full import coverage in some locales).

### Fixed

- **English translations** — replace German leftovers in `NowoTaskBoardBundle.en.yaml` (back to board, dates, description, assignees, subtasks, edit task).
- **Locale placeholders** — translate `task_board.board.task_placeholder` (and ES `task.edit`) instead of leaving English/German stubs.

### Changed

- **Release process** — `make release-check` includes `check-no-cursor-coauthor`; RELEASE.md reminds to re-check after the release commit (REQ-GIT-001).

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

[Unreleased]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.4...v1.3.0
[1.2.4]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.3...v1.2.4
[1.2.3]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/nowo-tech/TaskBoardBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/TaskBoardBundle/releases/tag/v1.0.0
