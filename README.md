# TaskBoard Bundle

[![CI](https://github.com/nowo-tech/TaskBoardBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/TaskBoardBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/task-board-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/task-board-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/task-board-bundle.svg)](https://packagist.org/packages/nowo-tech/task-board-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/TaskBoardBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/TaskBoardBundle) [![Coverage](https://img.shields.io/badge/Coverage-PHP%20%2B%20TS-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a **star** on [GitHub](https://github.com/nowo-tech/TaskBoardBundle) so more developers can find it.

Symfony bundle for **task boards, teams, and managers** (kanban, list, Gantt). Optional integration with [TimeTrackBundle](https://github.com/nowo-tech/TimeTrackBundle) for timers and time aggregation.

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly** (worker mode with kernel not reset between requests — see [FRANKENPHP-WORKER-AUDIT.md](docs/FRANKENPHP-WORKER-AUDIT.md)).

## Features

- Boards, columns, tasks, teams, and team members (manager/member roles)
- **Task import** from ClickUp, Jira, and Trello (CSV/JSON) via manage UI or `nowo:task-board:import`
- Web UI at `/tools/task-board` (kanban, list, Gantt, task detail)
- Vite + Stimulus assets for board interactions
- **Multi-tenant hosts (1.10, opt-in):** boards per tenant under the host's own URLs (`tenancy.tenant_paths`), links from tasks to host objects (issues, orders…) with a resolver, an assignee provider, events after every task write, and template blocks / partials with a recursive subtask tree — see [Usage](docs/USAGE.md#multi-tenant-hosts-since-110); since 2.6 each tenant path may say whether its index opens a single board (`tenant_paths[].open_single_board`)
- **Columns and task panel (1.11):** column icons, rename / colour / delete, reusable column sets (save a board's columns, apply them to a new or existing board), and a task panel fragment for side drawers (`data-task-panel-url` on cards, list rows and Gantt bars; since 2.6 the panel root carries `data-nowo-task-can-edit`); no inline styles in the templates — see [Usage](docs/USAGE.md#columns-column-sets-and-the-task-panel-since-111)
- **Comments and @mentions (1.16):** comments interleaved with the task activity, @mentions of the people who can read the task (host resolver), events to notify them — see [Usage](docs/USAGE.md#comments-and-mentions-since-116)
- **Dependencies, time and Gantt scales (1.15):** add / remove dependencies with cycle checks, time tracking with the estimate deviation, list time columns, Gantt in hours / days / weeks / months with a configurable week start — see [Usage](docs/USAGE.md#dependencies-time-and-gantt-scales-since-115)
- **Provider sync with ClickUp (1.23, opt-in; full import 1.25):** follow a ClickUp list, folder or space; since 1.25 people are mapped through a per-tenant directory (verified e-mails, a mapping never grants access), with creator and creation date, assignees, watchers, checklists, comment authors, status types and tracked time handed to the host — see [sync.md](docs/sync.md). The bundle only reads ClickUp (`GET`) and never stores a token. *ClickUp returns other people's tracked time only to workspace owners and admins*: with a member token only the token user's own time is imported.
- **Multi-board task list (2.13, opt-in):** "All tasks" over the boards a host lets the viewer read (deny by default, every board re-checked), with a Board / Project column, filters, server-side pagination, the workload per person and a formula-safe CSV / XLSX export — see [Usage](docs/USAGE.md#multi-board-task-list-since-213)
- **Optional TimeTrack integration** — task provider, team context, and automatic `total_time_seconds` aggregation when TimeTrack is installed

## Installation

```bash
composer require nowo-tech/task-board-bundle
```

For time tracking, also install TimeTrack and wire the providers:

```bash
composer require nowo-tech/time-track-bundle
```

```yaml
# config/packages/nowo_time_track.yaml
nowo_time_track:
    task_provider: nowo_task_board.task_provider
    team_context_provider: nowo_task_board.team_context_provider
```

See [Installation](docs/INSTALLATION.md).

## Demo (with TimeTrack)

```bash
make -C TimeTrackBundle/demo up-symfony8
# http://localhost:8024/tools/task-board
# http://localhost:8024/tools/time-track
```

Login: `demo@example.com` / `demo`

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Demo notes](docs/DEMO-FRANKENPHP.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [Import export guides](docs/import/README.md) (ClickUp, Jira, Trello)

## Tests and coverage

```bash
make test
make test-coverage
make test-ts
make release-check
```

| Language | Suite | Coverage | Gate |
| --- | --- | --- | --- |
| PHP | PHPUnit 10: `unit` + `functional` (HTTP pages and writes, Doctrine repositories on SQLite; MySQL in CI), `make test-coverage` | **100%** lines (4831/4831) | `make test-coverage-100` / `scripts/check-coverage.php` (100%, CI coverage job) |
| TS/JS | Vitest + v8, `make test-ts` (prints the global % with `.scripts/ts-coverage-percent.sh`) | **100%** (min of statements / branches / functions / lines; 148/148 lines) | `vitest.config.ts` thresholds (100%) |
| Python | — | N/A | — |

Figures from CI (`ci.yml`, coverage job, PHP 8.2 + Symfony 7.4) on 2026-11-01. Only `src/Resources` (Twig,
translations, config, built assets) is outside the PHP measure; see [CONTRIBUTING](docs/CONTRIBUTING.md#test-suites-req-test-003).

## License

MIT — see [LICENSE](LICENSE).
