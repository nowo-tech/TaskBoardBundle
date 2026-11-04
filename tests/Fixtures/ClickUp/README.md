# ClickUp API v2 fixtures

**Status: PROVISIONAL.** These files were written by hand from the ClickUp API v2 documentation (2026-11-04). They
are not a recording yet. The owner records them once with a throwaway workspace:

```bash
CLICKUP_RECORD_TOKEN=pk_… make record-clickup-fixtures       # or: php scripts/record-clickup-fixtures.php
```

The script reads the token from the environment only, redacts every token, name, e-mail, avatar and id-bearing URL,
and overwrites the files below. Then replace "PROVISIONAL" with "RECORDED <date>" here.

`tests/Unit/Sync/ScrubbedFixturesTest.php` fails if a file contains a token (`pk_` + 8 characters or more), an e-mail
outside `example.test`, an avatar URL, a signed query string, a workspace name other than `Workspace <n>`, or a host
outside the fixture allowlist.

| File | Endpoint |
| --- | --- |
| `team.json` | `GET team` |
| `team_9000001_space.json` | `GET team/{id}/space?archived=false` |
| `space_90001_folder.json` | `GET space/{id}/folder?archived=false` |
| `space_90001_list.json` | `GET space/{id}/list?archived=false` (folderless lists) |
| `folder_90002_list.json` | `GET folder/{id}/list?archived=false` |
| `list_900200.json` | `GET list/{id}` |
| `list_900200_task_page0.json` | `GET list/{id}/task?page=0&subtasks=true&include_closed=true&include_markdown_description=true&order_by=updated&reverse=true` |
| `task_86a1x1_comment.json` | `GET task/{id}/comment` |

## TO CONFIRM with the recording (contract Q-O4)

1. **Attachment hosts.** The default allowlist is `*.clickup-attachments.com`, `attachments.clickup.com` and
   `attachments2.clickup.com`. Confirm every host the recorded `attachments[].url` and inline image URLs use.
2. **`attachments` in the list endpoint.** These fixtures put `attachments` on each task of `GET list/{id}/task`. If the
   recording shows the list answer has none, the adapter must call `GET task/{id}` for the tasks it creates or
   updates.
3. **Attachment fields.** `title`, `size`, `mimetype` and `date` are assumed.
4. **`last_page`.** Assumed on `GET list/{id}/task`; the adapter also stops on a page shorter than 100.
5. **Ordering.** `order_by=updated&reverse=true` is assumed to list the oldest update first.
6. **Comment paging.** `start` (date) and `start_id` of the oldest comment are assumed to return the older ones,
   25 at a time.
