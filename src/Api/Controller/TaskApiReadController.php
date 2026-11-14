<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiRevision;
use Nowo\TaskBoardBundle\Api\Value\TaskApiSpace;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUser;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_string;

/**
 * Since 2.2.0 (spec 213): the read endpoints. Thin: parse the request, call one operation, serialize.
 */
final readonly class TaskApiReadController
{
    public function __construct(
        private TaskApiReadOperations $operations,
        private TaskApiReadSerializer $serializer,
    ) {
    }

    public function spaces(Request $request, string $teamId): JsonResponse
    {
        $spaces = $this->operations->spaces(TaskApiRequestListener::principalOf($request), $teamId);

        return new JsonResponse(['spaces' => array_map(fn (TaskApiSpace $space): array => $this->serializer->space($space), $spaces)]);
    }

    public function space(Request $request, string $spaceId): JsonResponse
    {
        return new JsonResponse($this->serializer->space($this->operations->space(TaskApiRequestListener::principalOf($request), $spaceId)));
    }

    /** No folders: every board is a folderless list. */
    public function folders(Request $request, string $spaceId): JsonResponse
    {
        $this->operations->space(TaskApiRequestListener::principalOf($request), $spaceId);

        return new JsonResponse(['folders' => []]);
    }

    public function lists(Request $request, string $spaceId): JsonResponse
    {
        $list = $this->operations->boards(TaskApiRequestListener::principalOf($request), $spaceId);

        return new JsonResponse(['lists' => array_map(fn (TaskBoard $board): array => $this->serializer->board($board, $list->space, $list->taskCounts[$board->getId()] ?? 0), $list->boards)]);
    }

    public function list(Request $request, string $listId): JsonResponse
    {
        $list = $this->operations->board(TaskApiRequestListener::principalOf($request), $listId);

        return new JsonResponse($this->serializer->board($list->boards[0], $list->space, $list->taskCounts[$listId] ?? 0));
    }

    public function listTasks(Request $request, string $listId): JsonResponse
    {
        return new JsonResponse($this->serializer->taskPage($this->operations->tasks(TaskApiRequestListener::principalOf($request), $listId, TaskApiTaskFilter::fromQuery($request->query->all()))));
    }

    public function teamTasks(Request $request, string $teamId): JsonResponse
    {
        return new JsonResponse($this->serializer->taskPage($this->operations->teamTasks(TaskApiRequestListener::principalOf($request), $teamId, TaskApiTaskFilter::fromQuery($request->query->all()))));
    }

    public function task(Request $request, string $taskId): JsonResponse
    {
        $query     = $request->query->all();
        $principal = TaskApiRequestListener::principalOf($request);
        $markdown  = TaskApiTaskFilter::flag($query, 'include_markdown_description');
        // Since 2.10.0 (spec 214): ClickUp's `custom_task_ids=true` names the task by its number, within `space_id` or `list_id`.
        $page = TaskApiTaskFilter::flag($query, 'custom_task_ids')
            ? $this->operations->taskByNumber($principal, $taskId, self::text($query, 'space_id'), self::text($query, 'list_id'), $markdown)
            : $this->operations->task($principal, $taskId, $markdown);
        $found = $page->tasks[0];
        $task  = $this->serializer->task($found, $page);
        if (TaskApiTaskFilter::flag($query, 'include_subtasks')) {
            $task['subtasks'] = array_map(static fn ($subtask): array => ['id' => $subtask->getId(), 'name' => $subtask->getTitle()], $page->subtasks[$found->getId()] ?? []);
        }

        // Since 2.16.0 (spec 234, FR-018): the revision as an ETag, for `If-Match` on the update.
        return TaskApiRevision::etag(new JsonResponse($task), $found);
    }

    public function comments(Request $request, string $taskId): JsonResponse
    {
        $query   = $request->query->all();
        $start   = isset($query['start']) ? TaskApiTaskFilter::milliseconds($query['start'], 'start') : null;
        $startId = is_string($query['start_id'] ?? null) ? $query['start_id'] : null;
        $page    = $this->operations->comments(TaskApiRequestListener::principalOf($request), $taskId, $start, $startId);

        return new JsonResponse(['comments' => array_map(fn (TaskComment $comment): array => $this->serializer->comment($comment, $page->authors[$comment->getId()] ?? null, $page->updaters[$comment->getId()] ?? null), $page->comments)]);
    }

    public function members(Request $request, string $taskId): JsonResponse
    {
        $members = $this->operations->members(TaskApiRequestListener::principalOf($request), $taskId);

        return new JsonResponse(['members' => array_map(fn (TaskApiUser $user): ?array => $this->serializer->user($user), $members)]);
    }

    public function tags(Request $request, string $spaceId): JsonResponse
    {
        $tags = $this->operations->spaceTags(TaskApiRequestListener::principalOf($request), $spaceId);

        return new JsonResponse(['tags' => array_map(static fn (string $tag): array => ['name' => $tag, 'tag_fg' => null, 'tag_bg' => null], $tags)]);
    }

    public function fields(Request $request, string $listId): JsonResponse
    {
        $fields = $this->operations->boardFields(TaskApiRequestListener::principalOf($request), $listId);

        return new JsonResponse(['fields' => array_map(fn (TaskSyncField $field): array => $this->serializer->field($field), $fields)]);
    }

    /**
     * `start_date` and `end_date` in Unix milliseconds, `assignee` a comma-separated list of ids, `task_id`.
     */
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
    private static function text(array $query, string $name): ?string
    {
        return is_string($query[$name] ?? null) ? $query[$name] : null;
    }
}
