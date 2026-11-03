# ClickUp — export guide

TaskBoard accepts **ClickUp CSV** (`clickup_csv`) and **ClickUp JSON** (`clickup_json`) exports. **CSV is recommended** for most users.

## CSV export (recommended)

### From a List or Table view

1. Open the **List** or **Table** view for the ClickUp list you want to migrate.
2. Click the **⋯** (ellipsis) menu on the view toolbar.
3. Choose **Export** → **CSV** (or **Export view** → **CSV**, depending on your ClickUp version).
4. Save the downloaded file (e.g. `clickup-export.csv`).

### From Workspace settings (bulk export)

1. Go to **Settings** (workspace level) → **Imports / Exports** (or **Trash & Exports**).
2. Start a **CSV export** for the Space, Folder, or List you need.
3. Download the file when ClickUp finishes processing.

### Columns used by TaskBoard

The importer reads flexible header names. Include as many of these as your export allows:

| ClickUp column | Aliases accepted | Maps to |
|----------------|------------------|---------|
| Task Name | Name, Task name | Task title |
| Task ID | ID, Task id | External ID (deduplication) |
| Status | Stage | Kanban column |
| Priority | — | Task priority |
| Task Content | Content, Description | Description |
| Assignee Email | Assignee, Assignees | Assignee: e-mail via resolver, or names via the assignee provider (since 1.22) |
| Start Date | Start date, Start Date Text | Start date — the Gantt bar's left end (since 1.22) |
| Due Date | Due date, Due Date (date), Due Date Text | Due date |
| Date Created | Created, Date Created Text | Fingerprint of rows without a Task ID (since 1.22) |
| Dependencies / Waiting On | — | "Blocked by" Gantt relations, by task id (since 1.22) |
| Blocking | — | "Blocks" Gantt relations, by task id (since 1.22) |
| Time Estimate | Time Estimated, Estimate, Time Estimated Text | Estimated minutes |
| Tags | — | Tags |
| Parent ID | Parent Task ID, Parent | Subtask parent |
| Task URL | URL, Link | Source link |

**Minimum:** at least one row with a non-empty **Task Name**. Rows without a title are skipped.

Dates may be millisecond timestamps (the Workspace export) or text (`Friday, March 1st 2024, 9:46:52 am +01:00`,
`1/10/2024, 9:00:00 AM`). A dependency on a task that is neither in the file nor imported before is skipped with a
warning. **Time Spent** is not imported (see [the overview](README.md#field-mapping-overview)).

### Example CSV header

```csv
Task ID,Task Name,Status,Priority,Task Content,Assignee Email,Due Date,Time Estimate,Tags,Parent ID,Task URL
```

See [tests/Fixtures/clickup/sample.csv](../../tests/Fixtures/clickup/sample.csv) in this repository for a valid sample.

### Import

**UI:** Board → **Import tasks** → source **ClickUp (CSV)** → upload file.

**CLI:**

```bash
php bin/console nowo:task-board:import <board-uuid> clickup-export.csv --source=clickup_csv
```

---

## JSON export

Use `clickup_json` when you have a JSON dump that contains a **tasks array** or a top-level list of task objects (common in API responses or custom backups).

### Expected structure

The importer accepts several shapes, for example:

```json
{
  "tasks": [
    {
      "id": "1001",
      "name": "Setup project",
      "status": { "status": "To do" },
      "priority": { "priority": "high" },
      "text_content": "Description here",
      "due_date": "2026-08-01",
      "parent": null,
      "url": "https://app.clickup.com/t/1001"
    }
  ]
}
```

Or a JSON array of task objects at the root.

### Fields recognized

| JSON field | Aliases | Maps to |
|------------|---------|---------|
| `name` | `task_name`, `title` | Title |
| `id` | `task_id` | External ID |
| `status` | object with `status` or `name` | Kanban column |
| `priority` | object with `priority` or `name` | Priority |
| `text_content` | `description`, `content` | Description |
| `assignees` / `assignee` | array or object with `email` (else `username`) | Assignee |
| `start_date` | `startDate` | Start date (since 1.22) |
| `due_date` | `dueDate` | Due date |
| `date_created` | `dateCreated` | Fingerprint of tasks without an id (since 1.22) |
| `dependencies` | list of `{task_id, depends_on}` or ids | Gantt relations: blocked by what the task waits on, blocks what waits on it (since 1.22) |
| `time_estimate` | `timeEstimate` | Estimate |
| `tags` | array of names or `{ "name": "..." }` | Tags |
| `parent` | `parent_id` | Parent task |
| `url` | `task_url` | Source link |

### Import

**UI:** **ClickUp (JSON)**.

**CLI:**

```bash
php bin/console nowo:task-board:import <board-uuid> clickup-export.json --source=clickup_json
```

---

## Tips

- **Statuses as columns:** Enable **Create missing status columns** so each ClickUp status becomes a kanban column if it does not exist yet.
- **Subtasks:** Include **Parent ID** so subtasks are linked after import.
- **Re-import:** import a newer export at any time: tasks are matched by Task ID (CSV and JSON alike) and updated; see
  [Re-importing](README.md#re-importing-idempotent-upsert-since-122).
- **Assignees:** ClickUp CSV may export display names instead of e-mails; they resolve through the board's
  `assignee_provider` when exactly one assignable user has that display name (else a warning).

[← Back to export guides index](README.md)
