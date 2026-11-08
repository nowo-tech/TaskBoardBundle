# Templates (UiKit page contract)

Since **2.0.0** every page fills the UiKit canonical blocks (REQ-UI-003). The host themes the layouts and slots once (`nowo_ui_kit.layouts`, `nowo_ui_kit.theme`).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Fragments](#fragments)
- [Layout](#layout)
- [Public hooks](#public-hooks)

## Pages

| Page id (`web_ui.templates.<id>`) | Template | Scaffold | View model (`page`) |
| --- | --- | --- | --- |
| `index` | `manage/index.html.twig` | `page/list` | `ListPage`: boards as rows, `EmptyState`, New board |
| `import` | `manage/import.html.twig` | `page/form` | `FormPage` |
| `connection` | `manage/connection.html.twig` | `page/show` | `ShowPage`: both states in `nowo_ui_show_sections` |
| `task` | `manage/task.html.twig` | `page/show` | `ShowPage`: the body in `nowo_ui_show_after`, an empty scaffold header (the title is a field) |

## Unique pages

These extend `nowo_task_board_page_layout() ?? nowo_ui_layout.admin` and fill the canonical blocks.

| Page id | Template | Why |
| --- | --- | --- |
| `board` | `manage/board.html.twig` | Kanban. Its drag and drop moves to `sortable` after the test on a real device |
| `list` | `manage/list.html.twig` | One `table` per column, with client filters and totals, so no single list scaffold fits |
| `gantt` | `manage/gantt.html.twig` | The chart |
| `calendar` | `manage/calendar.html.twig` | A grid of days with bars across them, or days as a list. Since 2.1, with `calendar.enabled` |
| `task_recurrence` | `manage/task_recurrence.html.twig` | The Repeat page: rule, what is copied (folded), pause, skip, stop. Since 2.3, with `recurrence.enabled`; the panel's section is the block `nowo_task_repeat` |
| `task_move`, `task_duplicate` | `manage/task_move.html.twig`, `manage/task_duplicate.html.twig` | Choices, preview and confirmation of a move or a duplicate. Since 2.3, with `move.enabled` |
| `connection_people` | `manage/connection_people.html.twig` | A long form of one row per person, with pills and a pager. Every part uses slots |

## Fragments

No layout: `task_panel`, `_board_create_form` (dialog answer; since 2.5.0 `action`, `form_id`, `testid` for a page outside a tenant) and `_board_create_actions` (its dialog footer, since 2.5.0), `_connection_strip` (since 2.5.0, `sync.badge.style: strip`), `_connection_containers`, `_connection_badge`, `_connection_progress`, `_connection_history`, `_connection_people_summary`.

## Layout

`web_ui.layout_template`: null = `nowo_ui_kit.layouts.admin`. A pre-2.0 layout, one without `nowo_ui_page_header`, goes through `manage/_legacy_layout.html.twig` until 3.0. See [UPGRADING](UPGRADING.md#to-200-uikit-page-contract).

## Public hooks

Stable across minors: `data-nowo-task-title`, `data-nowo-task-column` (cards, list rows, Gantt bars, subtasks, calendar chips and rows), `data-nowo-task-panel` (panel fragment), since 2.6.0 `data-nowo-task-can-edit` on it (`true` / `false`, the server's edit decision), since 2.5.0 `data-nowo-task-nav` (`view` / `subtasks`), `data-nowo-task-nav-position`, `data-nowo-task-nav-context` (`panel` / `page`) and `data-nowo-task-edit-form`, `data-task-id`, `data-task-panel-url`, the classes in [UPGRADING](UPGRADING.md#to-200-uikit-page-contract), and the page roots `nowo-task-page`, `nowo-task-panel-fragment`.
