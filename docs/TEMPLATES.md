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
| `connection_people` | `manage/connection_people.html.twig` | A long form of one row per person, with pills and a pager. Every part uses slots |

## Fragments

No layout: `task_panel`, `_board_create_form` (dialog answer), `_connection_containers`, `_connection_badge`, `_connection_progress`, `_connection_history`, `_connection_people_summary`.

## Layout

`web_ui.layout_template`: null = `nowo_ui_kit.layouts.admin`. A pre-2.0 layout, one without `nowo_ui_page_header`, goes through `manage/_legacy_layout.html.twig` until 3.0. See [UPGRADING](UPGRADING.md#to-200-uikit-page-contract).

## Public hooks

Stable across minors: `data-nowo-task-title`, `data-nowo-task-column` (cards, list rows, Gantt bars, subtasks), `data-nowo-task-panel` (panel fragment), `data-task-id`, `data-task-panel-url`, the classes in [UPGRADING](UPGRADING.md#to-200-uikit-page-contract), and the page roots `nowo-task-page`, `nowo-task-panel-fragment`.
