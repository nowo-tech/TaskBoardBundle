<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Controller;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\TaskClientApiException;
use Nowo\TaskBoardBundle\ClientApi\TaskClientCall;
use Nowo\TaskBoardBundle\ClientApi\TaskClientIdempotency;
use Nowo\TaskBoardBundle\ClientApi\TaskClientInput;
use Nowo\TaskBoardBundle\ClientApi\TaskClientReader;
use Nowo\TaskBoardBundle\ClientApi\TaskClientWriter;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_int;
use function is_string;
use function preg_match;

/**
 * Since 3.4.0 (client API v1, Beacon Desktop BD-3b): the routes of `<client_api.prefix>/v1` (Beacon:
 * `/api/task-board/v1`), a thin facade over {@see TaskClientReader} and {@see TaskClientWriter}. Every request already
 * went through `TaskClientApiListener` (principal, scope, context, budget); an answer that shows a task carries its
 * revision as `ETag`. Stateless.
 */
final readonly class TaskClientApiController
{
    /** The routes: name => [method, path after `/v1`, action, scope]. */
    public const ROUTES = [
        'me'                    => ['GET', '/me', 'me', TaskClientPrincipal::SCOPE_READ],
        'my_tasks'              => ['GET', '/my-tasks', 'myTasks', TaskClientPrincipal::SCOPE_READ],
        'boards'                => ['GET', '/boards', 'boards', TaskClientPrincipal::SCOPE_READ],
        'board'                 => ['GET', '/boards/{boardId}', 'board', TaskClientPrincipal::SCOPE_READ],
        'board_tasks'           => ['GET', '/boards/{boardId}/tasks', 'boardTasks', TaskClientPrincipal::SCOPE_READ],
        'task'                  => ['GET', '/tasks/{taskId}', 'task', TaskClientPrincipal::SCOPE_READ],
        'task_subtasks'         => ['GET', '/tasks/{taskId}/subtasks', 'subtasks', TaskClientPrincipal::SCOPE_READ],
        'task_comments'         => ['GET', '/tasks/{taskId}/comments', 'comments', TaskClientPrincipal::SCOPE_READ],
        'task_questions'        => ['GET', '/tasks/{taskId}/questions', 'questions', TaskClientPrincipal::SCOPE_READ],
        'questions_waiting'     => ['GET', '/questions/waiting', 'waiting', TaskClientPrincipal::SCOPE_READ],
        'people'                => ['GET', '/people', 'people', TaskClientPrincipal::SCOPE_READ],
        'changes'               => ['GET', '/changes', 'changes', TaskClientPrincipal::SCOPE_READ],
        'task_create'           => ['POST', '/tasks', 'create', TaskClientPrincipal::SCOPE_WRITE],
        'task_update'           => ['PATCH', '/tasks/{taskId}', 'update', TaskClientPrincipal::SCOPE_WRITE],
        'task_move'             => ['POST', '/tasks/{taskId}/move', 'move', TaskClientPrincipal::SCOPE_WRITE],
        'task_member_put'       => ['PUT', '/tasks/{taskId}/members/{userId}', 'memberPut', TaskClientPrincipal::SCOPE_WRITE],
        'task_member_delete'    => ['DELETE', '/tasks/{taskId}/members/{userId}', 'memberDelete', TaskClientPrincipal::SCOPE_WRITE],
        'checklist_item_create' => ['POST', '/tasks/{taskId}/checklist-items', 'checklistItemCreate', TaskClientPrincipal::SCOPE_WRITE],
        'checklist_item_update' => ['PATCH', '/tasks/{taskId}/checklist-items/{itemId}', 'checklistItemUpdate', TaskClientPrincipal::SCOPE_WRITE],
    ];

    public function __construct(
        private TaskClientReader $reader,
        private TaskClientWriter $writer,
        private TaskClientIdempotency $idempotency,
        private int $maxBodyBytes = 1048576,
    ) {
    }

    public function me(Request $request): JsonResponse
    {
        return $this->json($this->reader->me(TaskClientCall::of($request)));
    }

    public function myTasks(Request $request): JsonResponse
    {
        return $this->json($this->reader->myTasks(TaskClientCall::of($request), $request));
    }

    public function boards(Request $request): JsonResponse
    {
        return $this->json($this->reader->boards(TaskClientCall::of($request), $request));
    }

    public function board(Request $request, string $boardId): JsonResponse
    {
        return $this->json($this->reader->board(TaskClientCall::of($request), $boardId));
    }

    public function boardTasks(Request $request, string $boardId): JsonResponse
    {
        return $this->json($this->reader->boardTasks(TaskClientCall::of($request), $boardId, $request));
    }

    /** `GET tasks/{id}`, or `GET tasks/123?board=<id>` (also `#123`): the task of that number in the board's sequence. */
    public function task(Request $request, string $taskId): JsonResponse
    {
        $call  = TaskClientCall::of($request);
        $board = $request->query->all()['board'] ?? null;
        if (is_string($board) && preg_match('/^#?[1-9]\d{0,8}$/D', $taskId) === 1) {
            $taskId = $this->reader->taskIdByNumber($call, $taskId, $board);
        }
        $answer = $this->reader->task($call, $taskId);

        return $this->json($answer, 200, $answer['task']['revision'] ?? null);
    }

    public function subtasks(Request $request, string $taskId): JsonResponse
    {
        return $this->json($this->reader->subtasks(TaskClientCall::of($request), $taskId, $request));
    }

    public function comments(Request $request, string $taskId): JsonResponse
    {
        return $this->json($this->reader->comments(TaskClientCall::of($request), $taskId, $request));
    }

    public function questions(Request $request, string $taskId): JsonResponse
    {
        $state = $request->query->all()['state'] ?? null;

        return $this->json($this->reader->questions(TaskClientCall::of($request), $taskId, is_string($state) && $state !== '' ? $state : null));
    }

    public function waiting(Request $request): JsonResponse
    {
        return $this->json($this->reader->waiting(TaskClientCall::of($request)));
    }

    public function people(Request $request): JsonResponse
    {
        return $this->json($this->reader->people(TaskClientCall::of($request), $request));
    }

    public function changes(Request $request): JsonResponse
    {
        return $this->json($this->reader->changes(TaskClientCall::of($request), $request));
    }

    /** `POST tasks`: `Idempotency-Key` required; `201 {task}` + `ETag`. */
    public function create(Request $request): Response
    {
        $call = TaskClientCall::of($request);
        $body = TaskClientInput::json($request, $this->maxBodyBytes);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, true, function () use ($call, $body): array {
            $task = $this->writer->create($call, $body);

            return [$this->taskResponse($call, $task, 201), $task->getBoard()];
        });
    }

    /** `PATCH tasks/{id}`: `If-Match` required. */
    public function update(Request $request, string $taskId): Response
    {
        $call    = TaskClientCall::of($request);
        $body    = TaskClientInput::json($request, $this->maxBodyBytes);
        $ifMatch = (int) TaskClientInput::ifMatch($request, true);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, false, function () use ($call, $body, $taskId, $ifMatch): array {
            $task = $this->writer->update($call, $this->taskId($taskId), $body, $ifMatch);

            return [$this->taskResponse($call, $task), $task->getBoard()];
        });
    }

    /** `POST tasks/{id}/move`: `If-Match` required for a column change, `*` accepted inside the column. */
    public function move(Request $request, string $taskId): Response
    {
        $call    = TaskClientCall::of($request);
        $body    = TaskClientInput::json($request, $this->maxBodyBytes);
        $ifMatch = TaskClientInput::ifMatch($request, true, true);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, false, function () use ($call, $body, $taskId, $ifMatch): array {
            $task = $this->writer->move($call, $this->taskId($taskId), $body, $ifMatch);

            return [$this->taskResponse($call, $task), $task->getBoard()];
        });
    }

    /** `PUT tasks/{id}/members/{userId}` `{role}`: `If-Match` required. */
    public function memberPut(Request $request, string $taskId, string $userId): Response
    {
        $call    = TaskClientCall::of($request);
        $body    = TaskClientInput::json($request, $this->maxBodyBytes);
        $ifMatch = (int) TaskClientInput::ifMatch($request, true);
        TaskClientInput::only($body, ['role']);
        $role = TaskMemberRole::tryFrom(TaskClientInput::string($body, 'role')) ?? throw TaskClientApiException::invalidField('role');

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, false, function () use ($call, $taskId, $userId, $role, $ifMatch): array {
            $task = $this->writer->member($call, $this->taskId($taskId), $userId, $role, $ifMatch);

            return [$this->taskResponse($call, $task), $task->getBoard()];
        });
    }

    /** `DELETE tasks/{id}/members/{userId}`: every role of that person; `If-Match` required. */
    public function memberDelete(Request $request, string $taskId, string $userId): Response
    {
        $call    = TaskClientCall::of($request);
        $ifMatch = (int) TaskClientInput::ifMatch($request, true);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, false, function () use ($call, $taskId, $userId, $ifMatch): array {
            $task = $this->writer->member($call, $this->taskId($taskId), $userId, null, $ifMatch);

            return [$this->taskResponse($call, $task), $task->getBoard()];
        });
    }

    /** `POST tasks/{id}/checklist-items` `{checklist, title}`: `Idempotency-Key` required; `201 {task}` (the parent). */
    public function checklistItemCreate(Request $request, string $taskId): Response
    {
        $call = TaskClientCall::of($request);
        $body = TaskClientInput::json($request, $this->maxBodyBytes);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, true, function () use ($call, $body, $taskId): array {
            $item   = $this->writer->createChecklistItem($call, $this->taskId($taskId), $body);
            $parent = $item->getParent() ?? $item;

            return [$this->taskResponse($call, $parent, 201, ['itemId' => $item->getId()]), $parent->getBoard()];
        });
    }

    /** `PATCH tasks/{id}/checklist-items/{itemId}` `{title?, done?}`: the item's `If-Match`; `200 {task}` (the parent). */
    public function checklistItemUpdate(Request $request, string $taskId, string $itemId): Response
    {
        $call    = TaskClientCall::of($request);
        $body    = TaskClientInput::json($request, $this->maxBodyBytes);
        $ifMatch = (int) TaskClientInput::ifMatch($request, true);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless helper keeps no reference to it.
        return $this->idempotency->run($request, $call, false, function () use ($call, $body, $taskId, $itemId, $ifMatch): array {
            $item   = $this->writer->updateChecklistItem($call, $this->taskId($taskId), $this->taskId($itemId), $body, $ifMatch);
            $parent = $item->getParent() ?? $item;

            return [$this->taskResponse($call, $parent, 200, ['itemId' => $item->getId(), 'itemRevision' => $item->getRevision()]), $parent->getBoard()];
        });
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function taskResponse(TaskClientCall $call, Task $task, int $status = 200, array $extra = []): JsonResponse
    {
        return $this->json(['task' => $this->reader->written($call, $task)] + $extra, $status, $task->getRevision());
    }

    /** A path id that is not one is `404` (the same answer as an unknown one). */
    private function taskId(string $id): string
    {
        return TaskClientInput::isId($id) ? $id : throw TaskClientApiException::notFound();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200, mixed $revision = null): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        if (is_int($revision)) {
            $response->headers->set('ETag', '"' . $revision . '"');
        }

        return $response;
    }
}
