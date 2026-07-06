# Installation

## Requirements

- PHP 8.2+ (< 8.6)
- Symfony 7.4+ or 8.x
- Doctrine ORM 2.15+ or 3.x
- Doctrine Bundle 2.10+ (Symfony 7.x) or 3.0+ (Symfony 8.x)

**Recommended** (installed by the Flex recipe for rich task descriptions):

- `nowo-tech/tiptap-editor-bundle`
- `nowo-tech/tag-input-bundle`

**Optional** (time tracking integration):

- [TimeTrack Bundle](https://github.com/nowo-tech/TimeTrackBundle) ^1.0 — task provider, team context, and automatic `total_time_seconds` aggregation

## Composer

```bash
composer require nowo-tech/task-board-bundle
```

TaskBoard works standalone. To enable TimeTrack integration:

```bash
composer require nowo-tech/time-track-bundle
```

## Symfony Flex recipe

When using Flex, the recipe registers:

- `config/packages/nowo_task_board.yaml`
- `config/routes/nowo_task_board.yaml`

Manual install:

```php
// config/bundles.php
Nowo\TaskBoardBundle\TaskBoardBundle::class => ['all' => true],
```

```yaml
# config/routes/nowo_task_board.yaml
nowo_task_board:
    resource: .
    type: nowo_task_board
```

## TimeTrack integration (optional)

When TimeTrack is installed, TaskBoard registers `nowo_task_board.task_provider` and `nowo_task_board.team_context_provider` automatically. Wire them in your TimeTrack config:

```yaml
# config/packages/nowo_time_track.yaml
nowo_time_track:
    task_provider: nowo_task_board.task_provider
    team_context_provider: nowo_task_board.team_context_provider
```

Without TimeTrack, the bundle runs normally; time-tracking bridges and `TimeSpentAggregatorListener` are not loaded.

## Doctrine schema

Configure `user_class` and `table_prefix`, then update schema:

```bash
php bin/console doctrine:schema:update --force
# or create a migration
```

Tables use the prefix `task_board_` by default (e.g. `task_board_boards`, `task_board_tasks`, `task_board_teams`).

## Security firewall

Manage routes require authentication:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/tools/task-board, roles: ROLE_USER }
```

See [Configuration](CONFIGURATION.md) for `TaskBoardAccessCheckerInterface`, team membership resolver, and access events.

## Translations

The bundle ships **EN**, **ES**, **DE**, **FR**, **IT**, **NL**, and **PT** under the `NowoTaskBoardBundle` domain. Override in `translations/bundles/NowoTaskBoardBundle/` or your app's `translations/` folder.

## Assets

Install bundle public assets:

```bash
php bin/console assets:install
```

Templates load `asset('js/task-board.js', 'nowo_task_board')`. After upgrading to 1.1.1+, run `php bin/console assets:install`. Rebuild with `pnpm run build` in the bundle repo if you fork it.

## Demo

Combined demo with TimeTrack:

```bash
make -C TimeTrackBundle/demo up-symfony8
# http://localhost:8024/tools/task-board
# http://localhost:8024/tools/time-track
```

Login: `demo@example.com` / `demo`
