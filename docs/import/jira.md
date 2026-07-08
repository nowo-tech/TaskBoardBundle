# Jira — export guide

TaskBoard imports **Jira CSV** exports (`jira_csv`). The file must be comma- or tab-separated with a header row.

## Export from Jira Cloud

### From a board or backlog

1. Open the **board** or **backlog** for your project.
2. Open the **⋯** menu (board settings or project menu).
3. Choose **Export issues** or use **Filters** → **Advanced issue search** to scope issues, then export.

### From Issue Navigator (JQL)

1. Go to **Filters** → **Advanced issue search** (or `/issues/`).
2. Enter a JQL query, for example:

```text
project = MYPROJ ORDER BY rank
```

3. Click **Export** (top right) → **Export Excel CSV** or **Export CSV** (wording varies by Jira version).
4. Save the file (e.g. `jira-export.csv`).

### From a saved filter

1. Open **Filters** → select your saved filter.
2. **Export** → **CSV (all fields)** or **CSV (current fields)**.
3. Prefer exports that include **Issue key**, **Summary**, and **Status** at minimum.

> **Note:** Jira Data Center / Server UI labels may differ slightly. Use any CSV export that includes standard issue fields.

## Columns used by TaskBoard

| Jira column | Aliases accepted | Maps to |
|-------------|------------------|---------|
| Summary | Issue summary | Task title |
| Issue key | Key, Issue Key | External ID |
| Status | — | Kanban column |
| Priority | — | Task priority |
| Description | — | Description |
| Assignee | Assignee Id | Assignee (email if present) |
| Due Date | duedate, Due date | Due date |
| Original Estimate | Time Spent, Remaining Estimate | Estimated minutes |
| Labels | labels | Tags |
| Parent key | Parent, parent key | Subtask / epic link |
| URL | Issue URL | Source link |

**Minimum:** each row needs a non-empty **Summary**.

## Subtasks and hierarchy

If your export includes **Parent key** (or **Parent**), TaskBoard links imported issues as subtasks when the parent row is present in the same file. Import parent issues before children when possible; the orchestrator orders rows by parent dependency when IDs are available.

## Example CSV header

```csv
Issue key,Summary,Status,Priority,Description,Assignee,Due Date,Labels,Parent key
```

## Import

**UI:** Board → **Import tasks** → source **Jira (CSV)** → upload file.

**CLI:**

```bash
php bin/console nowo:task-board:import <board-uuid> jira-export.csv --source=jira_csv
```

## Tips

- **Workflow statuses:** Jira **Status** values map to kanban columns. Enable **Create missing status columns** for statuses that do not exist on the target board yet.
- **Rich text:** Descriptions may contain HTML; TaskBoard strips HTML on import.
- **Assignee:** Jira often exports display names, not emails. Implement `TaskImportUserResolverInterface` to map Jira usernames or emails to your User entity.
- **Re-import:** Issue keys are used as external IDs. Keep **Skip already imported tasks** enabled to avoid duplicates.
- **Large projects:** Export in batches (by sprint, component, or JQL) if your Jira plan limits CSV size, then import each file into the same board.

[← Back to export guides index](README.md)
