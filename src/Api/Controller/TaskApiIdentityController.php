<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiIdentitySerializer;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTeam;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function array_map;

/**
 * Since 2.2.0 (spec 213): `GET /v2/user` and `GET /v2/team`. Thin: every check is in the operation.
 */
final readonly class TaskApiIdentityController
{
    public function __construct(
        private TaskApiReadOperations $operations,
        private TaskApiIdentitySerializer $serializer,
    ) {
    }

    public function user(Request $request): JsonResponse
    {
        return new JsonResponse(['user' => $this->serializer->user($this->operations->me($this->principal($request)))]);
    }

    public function teams(Request $request): JsonResponse
    {
        $teams = $this->operations->teams($this->principal($request));

        return new JsonResponse(['teams' => array_map(fn (TaskApiTeam $team): array => $this->serializer->team($team), $teams)]);
    }

    private function principal(Request $request): TaskApiPrincipal
    {
        return TaskApiRequestListener::principalOf($request);
    }
}
