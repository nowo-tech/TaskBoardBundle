<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiFolderOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiRevision;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_string;

/**
 * Since 2.2.0 (spec 213): the read endpoints. Thin: parse the request, call one operation, serialize.
 */
#[Route(requirements: TaskBoardRouteLoader::API_REQUIREMENTS, defaults: TaskBoardRouteLoader::API_DEFAULTS)]
final readonly class TaskApiReadController
{
    public function __construct(
        private TaskApiReadOperations $operations,
        private TaskApiReadSerializer $serializer,
        // Since 2.19.0 (Beacon spec 234): folders.
        private TaskApiFolderOperations $folderOperations,
        // Since 2.19.0: `api.lists_include_folders`.
        private bool $listsIncludeFolders = false,
    ) {
    }

    #[Route('/v2/team/{teamId}/space', name: 'nowo_task_board_api_spaces', methods: ['GET'])]
    public function spaces(Request $request, string $teamId): JsonResponse
    {
        $spaces = $this->operations->spaces(TaskApiRequestListener::principalOf($request), $teamId);

        return new JsonResponse(['spaces' => array_map($this->serializer->space(...), $spaces)]);
    }

    #[Route('/v2/space/{spaceId}', name: 'nowo_task_board_api_space', methods: ['GET'])]
    public function space(Request $request, string $spaceId): JsonResponse
    {
        return new JsonResponse($this->serializer->space($this->operations->space(TaskApiRequestListener::principalOf($request), $spaceId)));
    }

    /** Since 2.19.0 (Beacon spec 234, FR-031): the space's folders, each with its lists (before: always empty). */
    #[Route('/v2/space/{spaceId}/folder', name: 'nowo_task_board_api_folders', methods: ['GET'])]
    public function folders(Request $request, string $spaceId): JsonResponse
    {
        $list = $this->folderOperations->folders(TaskApiRequestListener::principalOf($request), $spaceId);

        return new JsonResponse(['folders' => array_map(fn (TaskBoardFolder $folder): array => $this->serializer->folder($folder, $list->space, $list->boards[$folder->getId()] ?? [], $list->taskCounts), $list->folders)]);
    }

    /** Since 2.19.0: `GET /v2/folder/{id}`. */
    #[Route('/v2/folder/{folderId}', name: 'nowo_task_board_api_folder', methods: ['GET'])]
    public function folder(Request $request, string $folderId): JsonResponse
    {
        $list   = $this->folderOperations->folder(TaskApiRequestListener::principalOf($request), $folderId);
        $folder = $list->folders[0];

        return new JsonResponse($this->serializer->folder($folder, $list->space, $list->boards[$folder->getId()] ?? [], $list->taskCounts));
    }

    /** Since 2.19.0: `GET /v2/folder/{id}/list`: the lists of the folder. */
    #[Route('/v2/folder/{folderId}/list', name: 'nowo_task_board_api_folder_lists', methods: ['GET'])]
    public function folderLists(Request $request, string $folderId): JsonResponse
    {
        $list   = $this->folderOperations->folder(TaskApiRequestListener::principalOf($request), $folderId);
        $boards = $list->boards[$list->folders[0]->getId()] ?? [];

        return new JsonResponse(['lists' => array_map(fn (TaskBoard $board): array => $this->serializer->board($board, $list->space, $list->taskCounts[$board->getId()] ?? 0), $boards)]);
    }

    /**
     * Since 2.19.0 (as ClickUp): the folderless lists only, a board in a folder being listed under its folder; with
     * `include_folders=true` (or `api.lists_include_folders`) every board, each with its `folder`.
     */
    #[Route('/v2/space/{spaceId}/list', name: 'nowo_task_board_api_lists', methods: ['GET'])]
    public function lists(Request $request, string $spaceId): JsonResponse
    {
        $query  = $request->query->all();
        $all    = isset($query['include_folders']) ? TaskApiTaskFilter::flag($query, 'include_folders') : $this->listsIncludeFolders;
        $list   = $this->operations->boards(TaskApiRequestListener::principalOf($request), $spaceId);
        $boards = $all ? $list->boards : array_values(array_filter($list->boards, static fn (TaskBoard $board): bool => $board->getFolderId() === null));

        return new JsonResponse(['lists' => array_map(fn (TaskBoard $board): array => $this->serializer->board($board, $list->space, $list->taskCounts[$board->getId()] ?? 0), $boards)]);
    }

    #[Route('/v2/list/{listId}', name: 'nowo_task_board_api_list', methods: ['GET'])]
    public function list(Request $request, string $listId): JsonResponse
    {
        $list = $this->operations->board(TaskApiRequestListener::principalOf($request), $listId);

        return new JsonResponse($this->serializer->board($list->boards[0], $list->space, $list->taskCounts[$listId] ?? 0));
    }

    #[Route('/v2/list/{listId}/task', name: 'nowo_task_board_api_list_tasks', methods: ['GET'])]
    public function listTasks(Request $request, string $listId): JsonResponse
    {
        return new JsonResponse($this->serializer->taskPage($this->operations->tasks(TaskApiRequestListener::principalOf($request), $listId, TaskApiTaskFilter::fromQuery($request->query->all()))));
    }

    #[Route('/v2/team/{teamId}/task', name: 'nowo_task_board_api_team_tasks', methods: ['GET'])]
    public function teamTasks(Request $request, string $teamId): JsonResponse
    {
        return new JsonResponse($this->serializer->taskPage($this->operations->teamTasks(TaskApiRequestListener::principalOf($request), $teamId, TaskApiTaskFilter::fromQuery($request->query->all()))));
    }

    #[Route('/v2/task/{taskId}', name: 'nowo_task_board_api_task', methods: ['GET'])]
    public function task(Request $request, string $taskId): JsonResponse
    {
        $query     = $request->query->all();
        $principal = TaskApiRequestListener::principalOf($request);
        $markdown  = TaskApiTaskFilter::flag($query, 'include_markdown_description');
        // Since 2.10.0 (spec 214): ClickUp's `custom_task_ids=true` names the task by its number, within `space_id` or `list_id`.
        $page = TaskApiTaskFilter::flag($query, 'custom_task_ids')
            ? $this->operations->taskByNumber($principal, $taskId, $this->text($query, 'space_id'), $this->text($query, 'list_id'), $markdown)
            : $this->operations->task($principal, $taskId, $markdown);
        $found = $page->tasks[0];
        $task  = $this->serializer->task($found, $page);
        if (TaskApiTaskFilter::flag($query, 'include_subtasks')) {
            $task['subtasks'] = array_map(static fn (Task $subtask): array => ['id' => $subtask->getId(), 'name' => $subtask->getTitle()], $page->subtasks[$found->getId()] ?? []);
        }

        // Since 2.16.0 (spec 234, FR-018): the revision as an ETag, for `If-Match` on the update.
        return TaskApiRevision::etag(new JsonResponse($task), $found);
    }

    #[Route('/v2/task/{taskId}/comment', name: 'nowo_task_board_api_task_comments', methods: ['GET'])]
    public function comments(Request $request, string $taskId): JsonResponse
    {
        $query   = $request->query->all();
        $start   = isset($query['start']) ? TaskApiTaskFilter::milliseconds($query['start'], 'start') : null;
        $startId = is_string($query['start_id'] ?? null) ? $query['start_id'] : null;
        $page    = $this->operations->comments(TaskApiRequestListener::principalOf($request), $taskId, $start, $startId);

        return new JsonResponse(['comments' => array_map(fn (TaskComment $comment): array => $this->serializer->comment($comment, $page->authors[$comment->getId()] ?? null, $page->updaters[$comment->getId()] ?? null), $page->comments)]);
    }

    #[Route('/v2/task/{taskId}/member', name: 'nowo_task_board_api_task_members', methods: ['GET'])]
    public function members(Request $request, string $taskId): JsonResponse
    {
        $members = $this->operations->members(TaskApiRequestListener::principalOf($request), $taskId);

        return new JsonResponse(['members' => array_map($this->serializer->user(...), $members)]);
    }

    #[Route('/v2/space/{spaceId}/tag', name: 'nowo_task_board_api_space_tags', methods: ['GET'])]
    public function tags(Request $request, string $spaceId): JsonResponse
    {
        $tags = $this->operations->spaceTags(TaskApiRequestListener::principalOf($request), $spaceId);

        return new JsonResponse(['tags' => array_map(static fn (string $tag): array => ['name' => $tag, 'tag_fg' => null, 'tag_bg' => null], $tags)]);
    }

    #[Route('/v2/list/{listId}/field', name: 'nowo_task_board_api_list_fields', methods: ['GET'])]
    public function fields(Request $request, string $listId): JsonResponse
    {
        // Since 2.19.0 (FR-028): the native fields first (writable), then the synced ones (read-only).
        $fields = $this->operations->allBoardFields(TaskApiRequestListener::principalOf($request), $listId);

        return new JsonResponse(['fields' => [
            ...array_map($this->serializer->nativeField(...), $fields['native']),
            ...array_map($this->serializer->field(...), $fields['synced']),
        ]]);
    }

    /**
     * `start_date` and `end_date` in Unix milliseconds, `assignee` a comma-separated list of ids, `task_id`.
     */
    #[Route('/v2/team/{teamId}/time_entries', name: 'nowo_task_board_api_time_entries', methods: ['GET'])]
    public function timeEntries(Request $request, string $teamId): JsonResponse
    {
        $query    = $request->query->all();
        $assignee = $query['assignee'] ?? '';
        if (!is_string($assignee)) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid assignee');
        }
        $taskId = $query['task_id'] ?? null;
        $page   = $this->operations->timeEntries(TaskApiRequestListener::principalOf($request), new TaskApiTimeQuery(
            teamId: $teamId,
            from: isset($query['start_date']) ? TaskApiTaskFilter::milliseconds($query['start_date'], 'start_date') : null,
            to: isset($query['end_date']) ? TaskApiTaskFilter::milliseconds($query['end_date'], 'end_date') : null,
            assigneeIds: array_values(array_filter(explode(',', $assignee), static fn (string $id): bool => $id !== '')),
            taskId: is_string($taskId) && $taskId !== '' ? $taskId : null,
        ));

        return new JsonResponse(['data' => array_map(fn (TaskApiTimeEntry $entry): array => $this->serializer->timeEntry($entry, $teamId), $page->entries)]);
    }

    /** @param array<mixed> $query */
    private function text(array $query, string $name): ?string
    {
        return is_string($query[$name] ?? null) ? $query[$name] : null;
    }
}
