# ClickUp API v2 fixtures

**Status: PROVISIONAL.** These files were written by hand from the ClickUp API v2 documentation (2026-11-04). They
are not a recording: unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy. The owner records them once with a throwaway workspace:

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
| `team_people.json` (since 1.25) | `GET team` with members: roles 1–4 on the user, a role on the member, no role, no / invalid e-mail |
| `user.json` (since 1.25) | `GET user` (the token user, E2) |
| `task_86a1x2.json` (since 1.25) | `GET task/{id}?include_markdown_description=true` (E5 detail fallback: watchers, checklists, `time_spent`) |
| `list_900200_task_people.json` (since 1.25) | task page with `creator`, `assignees`, `watchers`, nested `checklists`, `time_spent`, status `type` and `list`; a row without `watchers` / `checklists` |
| `list_900300.json`, `space_90001.json`, `folder_90002.json` (since 1.25) | `GET list/{id}` / `GET space/{id}` / `GET folder/{id}` with `statuses` (status → column map) |
| `team_9000001_task_page0.json` (since 1.25) | `GET team/{id}/task?space_ids[]=…` (folder / space sources) |
| `team_9000001_time_entries_admin.json` (since 1.25) | `GET team/{id}/time_entries?…&assignee=<ids>`: billable true / false / not a bool, a running timer, an entry without a task, one without a user |
| `team_9000001_time_entries_member.json` (since 1.25) | the same without `assignee` (the token user's own entries) |
| `task_86a1x1_time.json` (since 1.25) | `GET task/{id}/time` (legacy intervals, E8) |
| `time_entries_429.headers` (since 1.25) | the headers of a 429 answer (`retry-after` above the inline wait: the run pauses) |
| `list_{id}_task_page0_reverse_false.json` (after recording) | the same task page with `reverse=false` |
| `list_{id}_task_page1.json` (after recording) | `page=1` of the same list (`last_page`, page size) |
| `task_{id}.json` (after recording) | `GET task/{id}?include_markdown_description=true` |
| `task_{id}_comment_page2.json` (after recording, 25+ comments) | `GET task/{id}/comment?start=<date>&start_id=<id>` of the oldest comment |

## Unconfirmed against the live API (owner decision 2026-11-04); validated by Beacon after deploy

The adapter is defensive on each point (see UPGRADING, "Known unconfirmed ClickUp behaviour"). The recording script
stays for a later confirmation.

1. **Attachment hosts.** The default allowlist is `*.clickup-attachments.com`, `attachments.clickup.com` and
   `attachments2.clickup.com`; a file on another host is refused (`host_not_allowed`) and a host application adds more with
   `sync.providers.clickup.attachment_hosts`.
2. **`attachments` in the list endpoint.** These fixtures put `attachments` on each task of `GET list/{id}/task`. If the
   list answer has none, the tasks simply have no attachments synced (no extra call); Beacon's validation after deploy
   decides whether `GET task/{id}` is needed.
3. **Attachment fields.** `title` (or `name`), `size`, `mimetype` and `date` are read when present; only `id` and `url`
   are required, anything else is optional and extra fields are ignored.
4. **`last_page`.** Used when the answer has it; without it the adapter pages on until an empty page (a short page is
   not trusted to be the last), within the run's page caps.
5. **Ordering.** `order_by=updated&reverse=true` is meant to list the oldest update first (the contract text said
   `reverse=false`). The run does not depend on the order: the watermark is the newest date of all pages read and the
   next run's overlap window re-reads the edge.
6. **Comment paging.** The documented `start` (date) and `start_id` of the oldest comment; paging stops on a page
   shorter than 25, an empty page, a cursor that does not move, or the comment caps.

### Since 1.25 (people, time, sources), also unconfirmed

7. **Roles.** `role` is read on `members[].user`, else on the member; 1 owner, 2 admin, 3 member, 4 guest; anything
   else is no role. Whether guests are listed by `GET team` is unknown.
8. **Workspace of a list.** `GET list/{id}` (or `folder/{id}`) gives the space; the workspaces' spaces are walked to
   find it (one call per workspace).
9. **Watchers and checklists on list rows.** Read when the key is present; a row without the key leaves them
   *unknown* (`watchersKnown` / `checklistsKnown` false), and the bundle does not call `GET task/{id}` (E5) for them
   in 1.25.
10. **Time entries.** `start`, `end`, `duration` and `at` in milliseconds (strings or numbers); a negative duration is
    a running timer; no paging (the window is the unit, halved by the run above `max_entries_per_window`); the
    `assignee` list length limit; what a member token gets with other users in `assignee` (the adapter never sends
    them in member mode).
11. **Legacy intervals (E8).** Deprecated upstream: 404 / 410 mean "no longer served".
12. **Sources.** The filtered workspace endpoint takes `space_ids[]` / `project_ids[]` (folders) and is assumed to
    honour `include_markdown_description`; a folder's statuses are its own `statuses` when present, else the union of
    its lists' statuses.

Unexpected answer shapes (a missing `tasks` / `comments` / `attachments` list, items that are not objects, no
`last_page`) are logged at debug level with their keys only, never a value.
