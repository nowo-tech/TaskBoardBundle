# Spec-driven development — TaskBoardBundle

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **TaskBoardBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Bundle functional scope

**Goal:** ClickUp-style **team task management** for Symfony applications: boards, kanban/list/Gantt views, subtasks, dependencies, external imports (ClickUp/Jira/Trello), MR/PR links, time estimates, documentation pages, stakeholder assignment, and optional **TimeTrackBundle** integration — embeddable via Flex recipe, configurable routes, table prefix, and event-driven ACL.

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As a** team member, **I want** to create boards and columns **so that** work is organized by project or squad. |
| US-02 | **As a** developer, **I want** to attach GitLab/GitHub MR links to tasks **so that** code review context stays with the work item. |
| US-03 | **As a** project lead, **I want** subtasks and task dependencies (blocks / blocked by) **so that** I can plan delivery order. |
| US-04 | **As a** team member, **I want** board and list views **so that** I can switch between kanban and tabular workflows. |
| US-05 | **As a** stakeholder, **I want** to be associated as watcher/reviewer **so that** I receive visibility without being the assignee. |
| US-06 | **As a** developer, **I want** estimated and logged time on tasks **so that** capacity planning is possible. |
| US-07 | **As a** technical writer, **I want** markdown documentation pages on tasks **so that** specs live next to the work. |
| US-08 | **As an** integrator, **I want** configurable routes, table prefix, templates, and access events **so that** the bundle fits multiple host apps. |

## Domain model

```
TaskBoard (project / space)
├── BoardColumn[]          — kanban columns (position, optional status mapping)
└── Task[]
    ├── parent Task?       — subtask hierarchy (self-reference)
    ├── TaskLink[]         — MR, PR, issue, URL, doc links
    ├── TaskDependency[]   — blocks | blocked_by | related | duplicates
    ├── TaskMember[]       — assignee | reviewer | stakeholder | watcher
    ├── TaskDocument[]     — additional markdown pages
    └── TaskTimeEntry[]    — logged minutes (estimate on Task.estimatedMinutes)
```

### Enumerations

| Enum | Values |
| --- | --- |
| `TaskPriority` | `low`, `normal`, `high`, `urgent` |
| `TaskLinkType` | `url`, `merge_request`, `pull_request`, `issue`, `documentation`, `other` |
| `TaskDependencyType` | `blocks`, `blocked_by`, `related`, `duplicates` |
| `TaskMemberRole` | `assignee`, `reviewer`, `stakeholder`, `watcher` |

### Views

| View | Route (default) | Behaviour |
| --- | --- | --- |
| **Board** | `/tools/tasks/board/{boardId}` | Tasks grouped by `BoardColumn`, drag-and-drop (Stimulus) |
| **List** | `/tools/tasks/board/{boardId}/list` | Sortable/filterable table (status, assignee, priority, due date) |
| **Task detail** | `/tools/tasks/task/{taskId}` | Full CRUD: subtasks, links, deps, docs, time, members |

## Integration strategy (Vault / Yopass pattern)

| Concern | Mechanism |
| --- | --- |
| Host user entity | `nowo_task_board.user_class` (required) |
| Table names | `table_prefix` + Doctrine metadata listener |
| Routes | `TaskBoardRouteLoader` (`type: nowo_task_board`) |
| Templates | `nowo_task_board.templates.*` + override under `templates/bundles/NowoTaskBoardBundle/` |
| ACL | `TaskBoardAccessCheckerInterface` + Symfony events |
| Teams | `TaskBoardTeamMembershipResolverInterface` (optional) |
| Flex recipe | `.symfony/recipe/nowo-tech/task-board-bundle/1.0.0/` |

### Symfony events (`TaskBoardEvents`)

| Event | Hook |
| --- | --- |
| `BOARD_LIST_QUERY` / `BOARD_LIST_RESULT` | Filter boards visible to the current user |
| `TASK_LIST_QUERY` / `TASK_LIST_RESULT` | Filter tasks in board/list views |
| `BOARD_ACCESS_CHECK` | Grant/deny access to a board |
| `TASK_ACCESS_CHECK` | Grant/deny access to a task (teams, grants) |
| `TASK_READ_ONLY_RESOLVE` | View-only mode for stakeholders/watchers |
| `MEMBER_LIST_QUERY` | Whitelist users for assignee picker |

## Implementation phases

| Phase | Scope | Status |
| --- | --- | --- |
| **P0** | Bundle skeleton, entities, config, routes, events, repositories (interfaces) | Done |
| **P1** | Manage controllers, Twig board/list/detail, forms, translations (en + es) | Done |
| **P2** | Stimulus drag-and-drop board, list filters, subtask tree UI | Done |
| **P3** | Demo Symfony 8 (FrankenPHP), fixtures, migrations | Done |
| **P4** | GitLab MR link resolver (optional integration), webhooks | Partial (URL parser) |
| **P5** | 100% PHPUnit coverage, CI, full docs compliance | Planned |

**Explicit non-goals:** native mobile apps, real-time collaborative cursors, built-in email notifications, and behavior not documented in `docs/` or `specs/001-baseline/`.

---

## Validating the functional spec

- Run **`composer qa`** / **`make release-check`** and **PHPUnit**, **PHPStan**, **Vitest** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Manual demo: create board → kanban drag → list filters → Gantt dependencies → import CSV → attach MR link.
- With TimeTrack: timer on task updates aggregated `total_time_seconds`.

---

## REQ traceability

| REQ | Makefile / demo |
| --- | --- |
| REQ-TEST-001 | `make test`, `composer test` |
| REQ-TEST-008 | `make test-coverage` + `.scripts/php-coverage-percent.sh` |
| REQ-DEMO-005 | `demo/symfony8/Makefile` → `Demo started at: http://localhost:<PORT>` |
| REQ-DEMO-007 | `demo/symfony8` target `update-bundle` |
| REQ-TWIG-001 | `TwigPathsPass`, `docs/USAGE.md` |
| REQ-I18N-001 | `Resources/translations/`, `docs/USAGE.md` |

## Validation

- PHPUnit: extension load, routing, access checker, entity mapping
- Demo manual: create board → add tasks → link MR → set dependency → board/list toggle
- Static analysis: `make release-check`

## Engram

See [ENGRAM.md](ENGRAM.md) for product memory in IDE workflows.


## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and the requirement table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [USAGE.md](USAGE.md)
- [INSTALLATION.md](INSTALLATION.md)
