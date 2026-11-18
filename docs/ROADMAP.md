# Roadmap

Planned direction for TaskBoard Bundle, from a feature gap review of 2026-11-18 (on 2.22.0, with the 2.23 task
questions counted as present). Items are grouped by horizon and by area, and may change with feedback and capacity.
Every item stays opt-in where it adds tables, routes or UI, follows the host ports (standard Symfony and DDD hosts),
and must work in FrankenPHP worker mode with the kernel not reset between requests.

## Table of contents

- [Current state (v2.22)](#current-state-v222)
- [Next](#next)
- [Later](#later)
- [Considering](#considering)
- [Decided](#decided)

---

## Current state (v2.22)

| Area | What the bundle has (version) |
|------|-------------------------------|
| Boards and folders | Boards per team or tenant (1.0, 1.10), icon and description, archive, folders of boards with shared column sets and cross-board dependencies (2.19), numbering prefix (2.19), board configuration as data (2.19) |
| Columns | Rename, colour, icon or emoji, reorder, delete with warnings (1.11, 2.9, 2.10), column sets (1.11), column types open / custom / done / closed (2.19), a transition policy port and a humans-only flag (2.16) |
| Tasks and fields | Title, Markdown description through the host renderer (1.19), four priorities, start and due dates (1.15), estimate, colour (1.14), tags, task numbers (2.1), native custom fields: text, number, dropdown (2.19), task documents, previous / next task (1.20) |
| Hierarchy | Subtasks of any depth with a tree view (1.10), optional depth limit and reparenting (2.20), checklists as tagged subtasks |
| People | Several assignees, reviewers, stakeholders and watchers (1.0), host assignee policy (1.10), member pictures and badges (1.23, 2.18), teams with manager / member roles |
| Comments and activity | Comments with @mentions and edit (1.16, 2.16), activity history with the cause of each change (2.3), write provenance (2.18), questions on a task with recorded answers (2.23) |
| Links and files | External links, merge and pull request links, host object links (1.0, 1.10); attachments through the host store in the Task API (2.2) |
| Dependencies | Blocks, blocked by, related, duplicates, with cycle checks (1.15) and dependents (2.16) |
| Recurrence and templates | Recurring tasks with fixed dates or after completion (2.3), task templates (2.16), column sets |
| Views | Board with drag and drop and subtask progress on cards (1.12), list with groups, sorting and time columns (1.12–1.15), read-only Gantt in hours to months (1.13, 1.15), calendar in month / week / list with drag to reschedule (2.1), working days and leave (2.14), all tasks across boards with workload per person (2.13), default view per user (1.24), focus mode and context menus (2.22) |
| Filters and search | Client-side list filters (text, priority, column, over estimate), calendar and cross-board filters in the URL, text search in the Task API (2.16) |
| Bulk work | Atomic bulk update in the Task API (2.16), move and duplicate a task tree to another board (2.3) |
| Import and export | CSV / JSON import from three file formats and the bundle's own format (1.2, 1.18), a read-only provider sync (1.23–1.29), CSV / XLSX export of the cross-board list (2.13) |
| API | Versioned Task API with idempotency, optimistic locking and conflict answers (2.2, 2.16, 2.22), a channel for agent tools (2.4), events after every write (1.10) |
| Time | Time tracking integration, estimate deviation, billable manual entries (1.0, 1.15, 2.12) |
| Platform | Multi-tenant paths (1.10), quota checks, account erasure, seven languages, phone layouts, keyboard-reachable menus, axe checks in the demo end-to-end tests |

## Next

High value for a team board, and built mostly on what the bundle already has.

### Views and filters

- **Filters on the board view.** Assignee, priority, tag, due window and "mine" on the kanban page, as the list has; a
  busy board is unreadable without them.
- **Saved views.** Save a view's filters, grouping and sort under a name, per user or shared with the board; a team
  opens the same slice every day without rebuilding it.
- **Swimlanes.** Group the board's rows by assignee, priority, tag or parent task; shows who holds what and where work
  piles up.
- **Live board refresh.** The board, list and task panel update when someone else changes a card (moved, edited,
  added or archived), without a page reload; a team working on one board sees the same state and stops overwriting
  each other's moves.

### Flow

- **Work-in-progress limits per column.** A built-in limit, shown in the column header, warned or enforced through the
  existing transition policy; keeps the team finishing work before starting more.
- **Bulk edit in the web UI.** Select several cards or rows and change status, assignee, priority, due date or tags at
  once, over the existing atomic bulk operation; saves dozens of clicks after a planning session.
- **Undo the last change.** A short "undo" after a move, archive or bulk edit, as the calendar already offers for a
  reschedule; makes fast drag and drop safe.

### Fields and labels

- **Managed labels with colours.** A board or tenant catalogue of labels with a colour, rename and merge, instead of
  free text only; labels read at a glance and stop drifting in spelling.
- **More custom field types.** Date, checkbox, person, URL and multi-select next to text, number and dropdown; lets a
  board model its own process without code.

### Files

- **Attachments in the web UI.** Upload, list and remove files on the task page and panel through the host's existing
  attachment store; today only the API and the provider sync bring files in.

### Integrations

- **Outgoing webhooks.** Signed HTTP callbacks for the existing write events, with a delivery log and retries; lets a
  team connect chat, CI or its own services without host code.

## Later

Larger pieces that need a design pass or a new table.

### Planning

- **Iterations.** Time-boxed cycles with a start, an end and an objective; tasks join an iteration and unfinished ones roll to
  the next; gives a team a steady planning rhythm.
- **Relative size estimates.** A numeric size per task, summed per column, iteration and person, next to the time
  estimate; teams that plan by size rather than hours can use the board.
- **Milestones.** A dated marker that tasks point to, shown on the calendar and the Gantt, with progress; makes
  deadlines visible to everyone.
- **Editable Gantt.** Drag bars to move or resize dates and draw dependencies on the chart, with the working-day rules
  of the calendar; the timeline becomes a planning tool, not only a picture.
- **Reschedule dependants.** When a task's dates move, offer to shift the tasks that depend on it; keeps a plan
  consistent without editing each task.
- **Capacity per person.** Compare each person's open estimate in a date range with their working hours and leave (from
  the calendar provider); spots overload before it happens.

### Automation

- **Rules.** "When a trigger happens, if a condition holds, do an action" per board (move, assign, set a field, add a
  tag, comment), recorded with the existing change cause; removes repetitive manual steps.
- **Due date reminders.** An event a fixed time before a due date and when a task becomes overdue, for the host to turn
  into notices; nobody misses a deadline silently.

### Reporting

- **Board charts.** Cumulative flow, cycle and lead time, throughput and an iteration burndown, from the history the
  bundle already records; shows whether the team's flow improves.
- **Dashboards.** A page of widgets (counts, charts, overdue lists) across the boards a user may read; one place for a
  lead to see the state of several teams.

### Templates and intake

- **Board templates.** Save a board with its columns, fields, labels and sample tasks and create new boards from it;
  wires the existing board configuration to the 2.16 task templates.
- **Intake forms.** A form, internal or public through a host route, that creates a task in a chosen column with
  mapped fields; lets requests arrive in a structured way instead of by message.

### Sharing

- **Guest access.** Invite an outside person to one board or task with limited rights, through the host's access
  checker; clients and partners can follow and comment without a full account.
- **Read-only share links.** A revocable, expiring link to a board or task view; share progress with people outside
  the tool.

### Export

- **Board export in the bundle's own format.** Export a board, with subtasks, dependencies and dates, as the CSV / JSON
  the import already reads; gives backups and moves between installations a round trip.

## Considering

Ideas that need more feedback before they are planned.

- **Card covers.** An image from the task's attachments shown at the top of the card; helps visual boards (design,
  content).
- **Stale card highlight.** Cards fade or show a marker after a number of days without changes; old work stops hiding
  in plain sight.
- **Keyboard shortcuts.** Board-wide keys for new task, open, assign to me, move left / right and search, with a help
  dialog; faster for heavy users and better for accessibility.
- **Initiatives above tasks.** A grouping of tasks across boards with a rolled-up progress and dates; links daily work
  to larger outcomes.
- **Approval step.** A column or a task state that needs a named reviewer's approval before it moves on, using the
  reviewer role and the transition policy; formalises sign-off.
- **Comment reactions.** Quick reactions on comments; acknowledges without adding noise.
- **Formula fields.** A number field computed from other fields of the task; covers scoring and simple cost models.
- **Offline reading.** Cache the last board and task pages for reading without a connection; useful on the move.

## Decided

Already settled; not planned in another form.

- **Checklists:** stay subtasks tagged `checklist:<name>`, with no separate checklist table.
- **Custom fields from a provider sync:** read-only (409 `FIELD_001`); only the board's own fields are writable.
- **Tracked time:** never deleted through the Task API (`DELETE` of a time entry is always 403), and never deleted by a
  move, a re-sync or an erasure.
- **E-mail notifications:** the host's responsibility; the bundle dispatches events and sends nothing itself.
- **In-app notifications:** the host shows notifications from the bundle's events; the bundle keeps no notification
  feed of its own.
- **Goals and key results:** not in TaskBoard; they belong to the host or a separate bundle.
- **Native mobile clients:** not planned; the web UI covers phones.
- **Real-time multi-user cursors on the board:** not planned (the board does refresh on other people's changes, see
  [Next](#next)).
- **Provider sync:** read-only; the bundle never writes to the provider and never stores a token.
