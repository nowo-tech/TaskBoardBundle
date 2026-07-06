# Security

## Table of contents

- [Attack surface](#attack-surface)
- [Threats and mitigations](#threats-and-mitigations)
- [Access control model](#access-control-model)
- [Dependencies](#dependencies)
- [Reporting](#reporting)
- [Release security checklist](#release-security-checklist)

## Attack surface

| Input | Description |
|-------|-------------|
| **Manage routes** | Authenticated CRUD for boards, columns, tasks, members, and links under `/tools/task-board`. |
| **Task import** | Authenticated file upload (CSV/JSON) on `board_import`; parsed server-side by tagged importers. |
| **Task forms** | Symfony forms for board/task creation and updates (title, description, links, members). |
| **Configuration** | `nowo_task_board` YAML (routes, table prefix, access checker, team resolver). |
| **TimeTrack bridge** | Optional. When TimeTrack is installed, task provider exposes trackable tasks; access gated by `TaskAccessGuard`. |

## Threats and mitigations

| Threat | Risk | Mitigation |
|--------|------|------------|
| **Unauthorized board access** | Anonymous users open manage UI. | Symfony firewall + `access_control` on `/tools/task-board`; `TaskBoardAccessCheckerInterface`. |
| **IDOR on tasks/boards** | User edits another user's board or task. | Default repository scoping; extend with `BoardAccessCheckEvent` / `TaskAccessCheckEvent` listeners. |
| **Unauthorized time tracking** | User tracks time on tasks they cannot access. | `TaskAccessGuard::canTrack()` checks assignee and team membership before TimeTrack provider returns tasks. |
| **XSS in task content** | Malicious HTML in task descriptions. | Twig auto-escaping; rich text via TiptapEditorBundle when configured. |
| **CSRF on forms** | Cross-site form submission. | Symfony CSRF tokens on all manage forms. |
| **Malicious import files** | Oversized or crafted CSV/JSON uploads. | Authenticated route only; Symfony file upload limits; parsers validate structure; import runs in orchestrator with explicit source selection. |

## Access control model

1. **Route level** — `TaskBoardAccessCheckerInterface` (default: role-based via `ConfigurableTaskBoardAccessChecker`).
2. **Resource level** — optional event listeners on `TaskBoardEvents::*` for board/task list filtering and per-resource grants.
3. **Time tracking** — when TimeTrack is installed, `TaskAccessGuard` validates assignee or team membership before exposing tasks to TimeTrack.

## Dependencies

Run `composer audit` and Dependabot before releases.

TaskBoard has no required dependency on TimeTrack. Install `nowo-tech/time-track-bundle` only when you need timer integration.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md) for coordinated disclosure.

## Release security checklist

- [ ] No secrets in repo or demo `.env` committed
- [ ] `composer audit` clean
- [ ] Manage routes documented in INSTALLATION
- [ ] Access checker and events documented for integrators
