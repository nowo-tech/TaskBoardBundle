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

Until 1.21 a **Skip already imported tasks** checkbox (CLI: omit `--force`) skipped rows already imported. Since 1.22
the checkbox is gone and `--force` is deprecated and ignored: every import is an idempotent upsert (below).

### Assignee mapping

Exports include assignee **e-mails** or **names**. E-mails resolve through `TaskImportUserResolverInterface` — see
[CONFIGURATION.md](../CONFIGURATION.md#assignee-resolver). Since 1.22 names (ClickUp, Jira display names, Trello
members, the own format) resolve through the board's `assignee_provider` (`TaskAssigneeProviderInterface`): a name
assigns the one assignable user whose display name (`displayName`, `fullName`, e-mail, username — the label the member
picker shows) equals it, ignoring case and spaces. No match, several matches, or no `assignee_provider` configured: a
warning (once per name) and nobody is assigned.

## Re-importing: idempotent upsert (since 1.22)

Importing a file again — the same one, or a newer export of the same tool — **updates** the tasks an earlier import
created instead of skipping or duplicating them. Importing the same file twice changes nothing. The result (flash
message, CLI) counts every row as **created**, **updated**, **unchanged** or **skipped**.

### How a row finds its task

| Row | Matching key (stored on the task's import link) |
|-----|--------------------------------------------------|
| With a source id (Task ID, Issue key, card id, `id`) | (import scope, id) — `task_board_task_links.import_source` + `external_id` |
| Without an id | (import scope, `fp:` + fingerprint of parent, normalized title and source creation date) |

- **Import scope** = the tool on a board import: `clickup` (CSV and JSON share it, a task imported from one is
  matched from the other), `jira`, `trello`, `taskboard` (the bundle's own format). **Importing subtasks into a task**
  uses `<provider>@<task id>` for every source: it only matches what an earlier import put under that task, and one
  template can be imported under several tasks.
- **Fingerprint rows** (no id): the import warns how many rows were matched that way. Renaming a row in the source (or
  changing its parent or creation date) makes it a new task. Identical rows of one file stay apart (the 2nd gets
  `-2`, and so on), so they are matched again in the same order.
- A row repeating an id already seen in the file is **skipped** with a warning.

### What a re-import changes (precedence)

**The source wins for every field it has a value for; a field the row leaves empty keeps the task's value.** So what was
set in the application survives unless the source sets that field.

| Field | Re-import behaviour |
|-------|---------------------|
| Title | Always the source's (a title is required). |
| Column | The column of the row's status when the board has it (created when "Create missing status columns" is on); no status → kept. Moving into / out of the last column completes / reopens the task. |
| Priority | The source's when the row has one; ClickUp / Jira / own-format rows with an empty priority and every Trello card keep the task's. |
| Description, start, due, estimate | The source's when the row has a value, else kept (the creation-date fallback for the start applies to new tasks only). |
| Tags | Replaced by the source's tags when the row has at least one, else kept. |
| Assignees | **The source replaces the application's assignees** (an e-mail only counts when the board's `assignee_provider` accepts that user): when the row names someone who resolves, the task's assignees become exactly those people — an assignee added in the application is removed (history: member removed). Reviewers, watchers and stakeholders are never touched; a row with no assignee, or none that resolves, keeps the task's assignees. |
| Parent | Follows the source's parent row when it exists (in the file or imported before); a row without a parent keeps the task where it is — the task you import into only places **new** tasks. A move that would make a task its own ancestor is refused with a warning. |
| Dependencies | Added when missing; existing ones are never removed (a relation already there is silently left as is). |
| Links, comments, time, documents, colour | Never touched. |

Every change is written to the task history as if made by the importing user. A row that changes nothing writes nothing.

### Across boards

On a board import, a **source id already imported into another board of the same tenant** (boards with the same
`tenant_key`, or both without one) is **skipped**, with one warning that only counts the rows (no board, no title): an import never moves a task to another board and never
copies it. To bring the tasks to the new board, move them there (or delete them on the old one first). **Rows without
an id follow the same rule**: a fingerprint already imported into another board of the tenant is skipped too. The
bundle's own format (`taskboard_csv` / `taskboard_json`) and imports into a task are not checked: their ids and
fingerprints belong to the board or to the task imported into, so a template can be imported into several boards or
tasks. Boards of other tenants
are never looked at.

### Tasks imported before 1.22

Their links have no `import_source` and are **not matched**: only a link the importer wrote is trusted (anyone who
may edit a task can type an external id and a label in the link form). Run the one-off backfill of
[UPGRADING](../UPGRADING.md#unreleased) right after upgrading to keep re-importing older ClickUp / Jira / Trello
exports without duplicates; anything else imported before 1.22 (including the own format, on a board or into a task)
is created again by a re-import.

### Who may change what

An import only updates, moves, re-assigns or links tasks **the importing user may edit**
(`TaskBoardResourceAccessChecker::canEditTask()`: `TASK_ACCESS_CHECK` listeners and `markReadOnly()` apply). A row
that matches a task the user cannot edit is skipped (counted, no title), and such a task is never used as a parent or a
dependency target ("not found"). **Import subtasks** on a task only ever matches the tasks an earlier import put under
that same task (scope `<provider>@<task id>`), so the rights on one task never reach the rest of the board.

### Limits

`import.max_rows` (default 5000) rows per file and `import.max_cell_length` (default 65536 bytes) per CSV cell; a larger
file is refused with an error. Since 1.22.1 a CSV is also refused when its header or a row has more than
`import.max_columns` (default 512) columns, or when it holds more than `import.max_cells` (default 1 000 000) non-empty
cells. Every limit is checked while the file is read, and the error is shown in the user's language. Files are also
limited to 20 MB by the upload form.

## Field mapping overview

The importers normalize common column names. Missing columns are skipped; only **title** (or equivalent) is required per row.

| TaskBoard field | ClickUp CSV | Jira CSV | Trello JSON |
|-----------------|-------------|----------|-------------|
| Title | Task Name | Summary | card `name` |
| External ID | Task ID | Issue key | card `id` |
| Status | Status | Status | list name |
| Priority | Priority | Priority | — (kept on re-import) |
| Description | Task Content / Description | Description | card `desc` |
| Assignee | Assignee Email / Assignee / Assignees (e-mail or names) | Assignee (e-mail or display name) | card members (names) |
| Start date (Gantt) | Start Date | Start date / Custom field (Start date) | card `start` |
| Due date | Due Date | Due Date | card `due` |
| Estimate | Time Estimate / Time Estimated | Original Estimate / Remaining Estimate | — |
| Tags | Tags | Labels (repeated columns joined) | card labels |
| Parent | Parent ID | Parent key | card `idParent` |
| Dependencies (Gantt) | Dependencies / Waiting On (blocked by), Blocking (blocks) | Inward / Outward issue link (Blocks) | — |
| Creation date | Date Created | Created | — |
| Source URL | Task URL | Issue URL | card `shortUrl` |

**Dates** (since 1.22): millisecond timestamps (ClickUp), second timestamps, ISO 8601, `YYYY-MM-DD`, day-first and
month-first numeric dates (day first when both read: `03/04/2026` is 3 April 2026), `03/Nov/26 10:15 AM` (Jira) and
ClickUp's text dates (`Friday, March 1st 2024, 9:46:52 am +01:00`). A date without a time is midnight.

**Not imported:** time spent (ClickUp "Time Spent", Jira "Time Spent"). Logged time is made of time entries of a person
in the application, which an export cannot name, and re-importing would add it again.

**Creation date** (ClickUp `Date Created` / `date_created`, Jira `Created`): part of the fingerprint of rows without an
id and, **when the row has no start date, the start of a new task** (its Gantt bar starts when the work was created in
the source, not when it was imported). This happens on create only: a re-import sets the start only from a start date,
never from the creation date, so a start changed in the application is kept. The task's own creation date stays the
import time (audit).

**CSV files:** a UTF-8 byte order mark is ignored, quoted cells may span several lines (multi-line descriptions), and a
header that repeats joins its values with `;`.

## Further reading

- [Usage — import tasks](../USAGE.md#import-tasks-from-clickup-jira-or-trello)
- [Configuration — import routes and custom importers](../CONFIGURATION.md#task-import)
- [Task board CSV / JSON](taskboard.md) — the bundle's own format (subtasks, start dates, Gantt relations), since 1.18.
