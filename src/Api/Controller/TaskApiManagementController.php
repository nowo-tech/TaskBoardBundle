<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiBoardOperations;
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
    ) {
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
        $response->setStatusCode(Response::HTTP_ACCEPTED);
        $response->headers->set('Retry-After', '1');

        return $response;
    }

    public function createColumn(Request $request, string $listId): JsonResponse
    {
        $input = $this->input($request, ['orderindex', 'type']);

        return $this->board($request, $this->boards->createColumn($this->principal($request), $listId, $input), $input);
    }

    public function updateColumn(Request $request, string $listId, string $statusId): JsonResponse
    {
        $input = $this->input($request, ['orderindex', 'type']);

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
