<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use LogicException;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiBoardOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiFolderOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiIdempotencyListener;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Since 2.2.0 (spec 213): boards and columns (Full management). Each answers the board as `GET /v2/list/{id}` does, with
 * `warnings` for the fields nothing read.
 */
final readonly class TaskApiManagementController
{
    public function __construct(
        private TaskApiBoardOperations $boards,
        private TaskApiReadOperations $reads,
        private TaskApiReadSerializer $serializer,
        private int $maxBodyBytes,
        // Since 2.18.0 (Beacon spec 234): folders.
        private ?TaskApiFolderOperations $folders = null,
    ) {
    }

    /** Since 2.18.0: `POST /v2/space/{id}/folder`. */
    public function createFolder(Request $request, string $spaceId): JsonResponse
    {
        $input  = $this->input($request);
        $folder = $this->folderOperations()->createFolder($this->principal($request), $spaceId, $input);

        return $this->folder($request, $folder->getId(), $folder->getTenantKey(), $input);
    }

    /** Since 2.18.0: `PUT /v2/folder/{id}`. */
    public function updateFolder(Request $request, string $folderId): JsonResponse
    {
        $input  = $this->input($request);
        $folder = $this->folderOperations()->updateFolder($this->principal($request), $folderId, $input);

        return $this->folder($request, $folder->getId(), $folder->getTenantKey(), $input);
    }

    /** Since 2.18.0: `DELETE /v2/folder/{id}`: 200 `{}`; 409 `FOLDER_001` while lists are in it. */
    public function deleteFolder(Request $request, string $folderId): JsonResponse
    {
        $input = $this->input($request);
        TaskApiIdempotencyListener::targetTenant($request, $this->folderOperations()->deleteFolder($this->principal($request), $folderId));

        return $this->answer([], $input);
    }

    /** Since 2.18.0: `POST /v2/folder/{id}/list`: a board created in the folder. */
    public function createFolderList(Request $request, string $folderId): JsonResponse
    {
        $input = $this->input($request, TaskApiBoardOperations::NOT_SUPPORTED);

        return $this->board($request, $this->folderOperations()->createList($this->principal($request), $folderId, $input), $input);
    }

    private function folder(Request $request, string $folderId, ?string $tenantKey, TaskApiInput $input): JsonResponse
    {
        TaskApiIdempotencyListener::targetTenant($request, $tenantKey);
        $list   = $this->folderOperations()->folder($this->principal($request), $folderId);
        $folder = $list->folders[0];

        return $this->answer($this->serializer->folder($folder, $list->space, $list->boards[$folder->getId()] ?? [], $list->taskCounts), $input);
    }

    private function folderOperations(): TaskApiFolderOperations
    {
        return $this->folders ?? throw new LogicException('TaskApiFolderOperations is not wired.');
    }

    public function createBoard(Request $request, string $spaceId): JsonResponse
    {
        $input = $this->input($request, TaskApiBoardOperations::NOT_SUPPORTED);

        return $this->board($request, $this->boards->createBoard($this->principal($request), $spaceId, $input), $input);
    }

    public function updateBoard(Request $request, string $listId): JsonResponse
    {
        $input = $this->input($request, TaskApiBoardOperations::NOT_SUPPORTED);

        return $this->board($request, $this->boards->updateBoard($this->principal($request), $listId, $input), $input);
    }

    /**
     * 200 `{}` when the board is gone; 202 while tasks remain, with `Retry-After`: call `DELETE` again.
     */
    public function deleteBoard(Request $request, string $listId): JsonResponse
    {
        $input = $this->input($request);
        if ($this->boards->deleteBoard($this->principal($request), $listId)) {
            return $this->answer([], $input);
        }
        $response = $this->answer(['status' => 'in_progress'], $input);
        // @igor-ignore - Response built per call by answer(); not a shared service.
        $response->setStatusCode(Response::HTTP_ACCEPTED);
        // @igor-ignore - Response built per call by answer(); not a shared service.
        $response->headers->set('Retry-After', '1');

        return $response;
    }

    public function createColumn(Request $request, string $listId): JsonResponse
    {
        // Since 2.18.0 (FR-033): `type` is read.
        $input = $this->input($request, ['orderindex']);

        return $this->board($request, $this->boards->createColumn($this->principal($request), $listId, $input), $input);
    }

    public function updateColumn(Request $request, string $listId, string $statusId): JsonResponse
    {
        $input = $this->input($request, ['orderindex']);

        return $this->board($request, $this->boards->updateColumn($this->principal($request), $listId, $statusId, $input), $input);
    }

    public function deleteColumn(Request $request, string $listId, string $statusId): JsonResponse
    {
        $input = $this->input($request);

        return $this->board($request, $this->boards->deleteColumn($this->principal($request), $listId, $statusId), $input);
    }

    public function reorderColumns(Request $request, string $listId): JsonResponse
    {
        $input = $this->input($request);

        return $this->board($request, $this->boards->reorderColumns($this->principal($request), $listId, $input), $input);
    }

    private function board(Request $request, TaskBoard $board, TaskApiInput $input): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $board);
        $list = $this->reads->board($this->principal($request), $board->getId());

        return $this->answer($this->serializer->board($list->boards[0], $list->space, $list->taskCounts[$board->getId()] ?? 0), $input);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function answer(array $data, TaskApiInput $input): JsonResponse
    {
        $warnings = $input->warnings();
        if ($warnings !== []) {
            $data['warnings'] = $warnings;
        }

        return new JsonResponse($data === [] ? (object) [] : $data);
    }

    /**
     * @param list<string> $notSupported
     */
    private function input(Request $request, array $notSupported = []): TaskApiInput
    {
        return TaskApiInput::fromRequest($request, $this->maxBodyBytes, $notSupported);
    }

    private function principal(Request $request): TaskApiPrincipal
    {
        return TaskApiRequestListener::principalOf($request);
    }
}
