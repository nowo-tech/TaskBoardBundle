# Upgrading

This document describes how to upgrade between versions of TaskBoard Bundle.

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
- **TimeTrack:** `nowo-tech/time-track-bundle` ^1.0

### Install

```bash
composer require nowo-tech/task-board-bundle nowo-tech/time-track-bundle
php bin/console assets:install
php bin/console doctrine:schema:update --force
# or create a migration
```

Configure `user_class` and wire TimeTrack providers:

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

## Unreleased / 1.x

Breaking or notable changes in future 1.x releases will be documented here.
