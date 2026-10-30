# The bundle's own import format (since 1.18)

The only format that carries **start dates** and **Gantt relations**. Use it to import a plan into a board, or into a
task (**Import subtasks** on the task page / panel: rows without a parent become subtasks of that task).

## CSV

A header row, then one task per row; comma or semicolon separated. Column names are case-insensitive.

| Column | Meaning |
|--------|---------|
| `id` | Your own id for the row, used by `parent` and `depends_on` (optional, needed to link rows) |
| `parent` | The id of the parent row: this task becomes its subtask (any depth) |
| `title` | Required; rows without one are skipped |
| `description` | Plain text |
| `status` | Column name (created when "Create missing status columns" is on) |
| `priority` | `low`, `normal`, `high` or `urgent` |
| `assignee` | E-mail of a person the host can assign |
| `start`, `due` | `YYYY-MM-DD` |
| `estimate` | Minutes, or `1h30m` |
| `tags` | Separated by `;` or `,` |
| `depends_on` | Other rows' ids separated by `;` or `,` (use `,` in a semicolon CSV) |

In `depends_on`, `api` means *this task is blocked by api* (api finishes first: the Gantt arrow goes from api to this
task). `api:blocks`, `api:related` and `api:duplicates` give the other relations. A relation to a row that is not in
the file, a duplicate or one that would close a cycle is skipped with a warning.

```csv
id,parent,title,status,start,due,estimate,depends_on
design,,Design the screens,To do,2026-11-02,2026-11-06,8h,
api,,Build the API,To do,2026-11-04,2026-11-12,16h,
api-auth,api,Authentication endpoints,To do,2026-11-04,2026-11-06,6h,
release,,Release,To do,2026-11-13,2026-11-13,1h,design;api
```

## JSON

A list of tasks, or `{"tasks": [...]}`, with the same keys; `subtasks` nests children (a task with subtasks needs an
`id`), `tags` and `depends_on` may be lists.

```json
{"tasks": [
  {"id": "api", "title": "Build the API", "start": "2026-11-04", "due": "2026-11-12",
   "subtasks": [{"id": "api-auth", "title": "Authentication endpoints"}]},
  {"id": "release", "title": "Release", "depends_on": ["api", "api-auth:related"]}
]}
```

## CLI

```bash
php bin/console nowo:task-board:import <board-uuid> plan.csv --source=taskboard_csv
```
