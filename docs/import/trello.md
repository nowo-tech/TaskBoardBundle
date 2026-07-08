# Trello — export guide

TaskBoard imports the standard **Trello board JSON** export (`trello_json`). This is the native format Trello produces when you export a board as JSON.

## Export from Trello

1. Open the **board** you want to migrate.
2. Click **Show menu** (top-right, or board menu on mobile).
3. Under **Power-Ups** / **Automation**, open **Print, Export, and Share** (menu label may be **More** → **Print and export** on some layouts).
4. Choose **Export as JSON**.
5. Save the file (e.g. `board-export.json`).

> **Trello Free / plan limits:** JSON export availability depends on your Trello plan. If JSON export is not offered, use a Power-Up or the Trello API to obtain a board JSON with `lists` and `cards` arrays.

## JSON structure expected by TaskBoard

The export must be a JSON object with at least:

```json
{
  "lists": [
    { "id": "list-id-1", "name": "To Do" },
    { "id": "list-id-2", "name": "Doing" }
  ],
  "cards": [
    {
      "id": "card-id-1",
      "name": "My task",
      "desc": "Description",
      "idList": "list-id-1",
      "due": "2026-08-15T12:00:00.000Z",
      "labels": [{ "name": "bug" }],
      "shortUrl": "https://trello.com/c/abc123"
    }
  ]
}
```

### Field mapping

| Trello field | Maps to |
|--------------|---------|
| `cards[].name` | Task title |
| `cards[].id` | External ID |
| List `name` for `cards[].idList` | Kanban column (status) |
| `cards[].desc` | Description |
| `cards[].due` | Due date |
| `cards[].labels[].name` | Tags |
| `cards[].idParent` or `parent` | Subtask parent |
| `cards[].shortUrl` or `url` | Source link |

Trello exports do not include assignees or priority in the standard JSON; imported tasks use **Normal** priority unless you extend the importer.

## Lists → kanban columns

Each Trello **list** becomes a **status** name. If a list name does not match an existing column on the TaskBoard board, enable **Create missing status columns** during import.

Closed lists and archived cards are included if present in the JSON; you can filter them in Trello before export if needed.

## Import

**UI:** Board → **Import tasks** → source **Trello (JSON)** → upload file.

**CLI:**

```bash
php bin/console nowo:task-board:import <board-uuid> board-export.json --source=trello_json
```

## Tips

- **One board per file:** Trello JSON is per board. Import each exported board into a separate TaskBoard board (or merge manually with multiple import runs).
- **Checklists and attachments:** Standard JSON export includes checklists and attachments in separate sections; the built-in importer maps **cards** only. Copy checklist items manually or extend `TaskImporterInterface` for custom handling.
- **Members:** Member data is not mapped by default. Use `TaskImportUserResolverInterface` in a custom workflow if you parse member fields from an extended export.
- **Re-import:** Card IDs are stored as external IDs. Keep **Skip already imported tasks** enabled when re-running an import.

[← Back to export guides index](README.md)
