# Export guides for task import

TaskBoard Bundle can import tasks from third-party tools into an existing board. Each source has its own export format. Use these guides to produce a file that matches the built-in importers.

## Supported sources

| Source key (`--source`) | File type | Guide |
|-------------------------|-----------|-------|
| `clickup_csv` | CSV | [ClickUp export guide](clickup.md#csv-export-recommended) |
| `clickup_json` | JSON | [ClickUp export guide](clickup.md#json-export) |
| `jira_csv` | CSV | [Jira export guide](jira.md) |
| `trello_json` | JSON | [Trello export guide](trello.md) |

Accepted extensions: **`.csv`**, **`.txt`**, **`.tsv`** for CSV sources; **`.json`** for JSON sources.

## Before you import

1. **Create or open a target board** in TaskBoard (kanban columns must exist or be created during import).
2. **Export from the source tool** using the guide for that product.
3. **Upload in the manage UI** — board → **Import tasks** — or run the CLI:

```bash
php bin/console nowo:task-board:import <board-uuid> /path/to/export.csv --source=clickup_csv
```

### Import options

| Option | UI | CLI | Effect |
|--------|----|-----|--------|
| Create missing status columns | Checkbox (default on) | omit `--no-create-columns` | Adds kanban columns for statuses not on the board |
| Skip already imported tasks | Checkbox (default on) | omit `--force` | Skips rows whose external ID was imported before |

External IDs are stored on task links (`TaskLink.externalId`) so re-imports are idempotent.

### Assignee mapping

Exports often include assignee **names** or **emails**. To map emails to your User entity, implement `TaskImportUserResolverInterface` — see [CONFIGURATION.md](../CONFIGURATION.md#assignee-resolver).

## Field mapping overview

The importers normalize common column names. Missing columns are skipped; only **title** (or equivalent) is required per row.

| TaskBoard field | ClickUp CSV | Jira CSV | Trello JSON |
|-----------------|-------------|----------|-------------|
| Title | Task Name | Summary | card `name` |
| External ID | Task ID | Issue key | card `id` |
| Status | Status | Status | list name |
| Priority | Priority | Priority | (default Normal) |
| Description | Task Content / Description | Description | card `desc` |
| Assignee | Assignee Email / Assignee | Assignee | — |
| Due date | Due Date | Due Date | card `due` |
| Estimate | Time Estimate | Original Estimate | — |
| Tags | Tags | Labels | card labels |
| Parent | Parent ID | Parent key | card `idParent` |
| Source URL | Task URL | Issue URL | card `shortUrl` |

## Further reading

- [Usage — import tasks](../USAGE.md#import-tasks-from-clickup-jira-or-trello)
- [Export guides (ClickUp, Jira, Trello)](../import/README.md)
- [Configuration — import routes and custom importers](../CONFIGURATION.md#task-import)
