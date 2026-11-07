# Theming

TaskBoard paints with UiKit: pages take their classes from the theme slots of `NowoTaskBoardBundle` and their colours from tokens. Since 2.0.0 no Tabler or Bootstrap class or variable is required.

## Table of contents

- [Tokens](#tokens)
- [Theme slots](#theme-slots)
- [Dialogs and the kit parts](#dialogs-and-the-kit-parts)
- [From 1.x](#from-1x)

## Tokens

Set them on any ancestor. Each `--nowo-task-*` token defaults to the kit's token, so a host that themes UiKit themes the board too, and `--nowo-task-*` remains the place to change the board alone.

| Token | Default |
| --- | --- |
| `--nowo-task-surface` | `--nowo-ui-surface` |
| `--nowo-task-surface-muted`, `--nowo-task-hover` | `--nowo-ui-surface-muted` |
| `--nowo-task-border` | `--nowo-ui-border` |
| `--nowo-task-ink` | `--nowo-ui-text` |
| `--nowo-task-muted` | `--nowo-ui-muted` |
| `--nowo-task-accent` | `--nowo-ui-primary` |
| `--nowo-task-accent-soft`, `--nowo-task-info-soft` | `--nowo-ui-info-bg` |
| `--nowo-task-radius`, `--nowo-task-radius-sm` | `--nowo-ui-radius` |
| `--nowo-task-danger`, `--nowo-task-danger-soft` | `--nowo-ui-danger`, `--nowo-ui-danger-bg` |
| `--nowo-task-success`, `--nowo-task-success-soft` | `--nowo-ui-success-text`, `--nowo-ui-success-bg` |
| `--nowo-task-warning`, `--nowo-task-warning-soft` | `--nowo-ui-warning-text`, `--nowo-ui-warning-bg` |
| `--nowo-task-info` | `--nowo-ui-info-text` |
| `--nowo-task-purple`, `--nowo-task-purple-soft` | A literal: the kit has no purple |
| `--nowo-task-overlay` | `--nowo-ui-overlay` (the dialog backdrop) |
| `--nowo-task-overlay-blur` | `0` (1.x: `2px`) |

The full list of tokens, including the Gantt and mention ones, is in [USAGE](USAGE.md#theming-with-design-tokens).

## Theme slots

Buttons, cards, badges, notices and muted text print `nowo_ui_class(slot, 'NowoTaskBoardBundle')`. To restyle them for this bundle only:

```yaml
nowo_ui_kit:
    theme:
        bundles:
            NowoTaskBoardBundle:
                classes:
                    btn_secondary: 'my-cancel-button'
```

## Dialogs and the kit parts

Dialogs are the kit's `dialog` with the sizes `--nowo-dialog-{width,height}-{md,wide}`. Avatars are the kit's `avatar` (`--nowo-ui-avatar-*`), the subtask tree the kit's `tree`, the Markdown description's fold `show_more`. Their chrome is themed in UiKit, see its `THEMING.md`. The classes `nowo-task-dialog--native`, `--wide`, `__body`, `__actions`, `__footer` stay on the dialogs as hooks.

Pages load `nowo-ui.css` through the layout's `nowo_ui_styles`; a host layout with its own asset list must include it and print `nowo_ui_tokens()`.

## From 1.x

- `--tblr-*` no longer reaches these pages. Set `--nowo-task-*` or `--nowo-ui-*`.
- `.btn`, `.card`, `.page-header` inside task pages exist only if your CSS framework's slots add them.
- `templates.css_framework` is ignored: set `nowo_ui_kit.css_framework`.
