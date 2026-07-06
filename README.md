# TaskBoard Bundle

[![CI](https://github.com/nowo-tech/TaskBoardBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/TaskBoardBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/task-board-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/task-board-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/task-board-bundle.svg)](https://packagist.org/packages/nowo-tech/task-board-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/task-board-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/TaskBoardBundle) [![Coverage](https://img.shields.io/badge/Coverage-PHP%20%2B%20TS-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a **star** on [GitHub](https://github.com/nowo-tech/TaskBoardBundle) so more developers can find it.

Symfony bundle for **task boards, teams, and managers** (kanban, list, Gantt). Optional integration with [TimeTrackBundle](https://github.com/nowo-tech/TimeTrackBundle) for timers and time aggregation.

## Features

- Boards, columns, tasks, teams, and team members (manager/member roles)
- Web UI at `/tools/task-board` (kanban, list, Gantt, task detail)
- Vite + Stimulus assets for board interactions
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
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)

## Tests and coverage

```bash
make test
make test-coverage
make test-ts
make release-check
```

- **PHP:** PHPUnit with coverage report via `make test-coverage` (controllers and Doctrine repositories excluded from 100% target)
- **TS/JS:** Vitest via `pnpm run test:coverage`
- **Python:** N/A

## License

MIT — see [LICENSE](LICENSE).
