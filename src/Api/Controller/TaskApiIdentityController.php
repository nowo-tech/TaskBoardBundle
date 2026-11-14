<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiIdentitySerializer;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function is_string;

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
        $teams = $this->operations->teamsWithMembers($this->principal($request));

        return new JsonResponse(['teams' => array_map(fn (array $team): array => $this->serializer->team($team['team'], $team['members']), $teams)]);
    }

    /**
     * Since 2.16.0 (spec 234, FR-025): `GET /v2/team/{id}/member` (every member) and `?name=` (a prefix of the name or of
     * one of its words, case and accents aside: id, name and service-account flag only).
     */
    public function members(Request $request, string $teamId): JsonResponse
    {
        $principal = $this->principal($request);
        $name      = $request->query->all()['name'] ?? null;
        if ($name === null) {
            return new JsonResponse(['members' => array_map($this->serializer->member(...), $this->operations->teamMembers($principal, $teamId))]);
        }
        if (!is_string($name)) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid name');
        }

        return new JsonResponse(['members' => array_map($this->serializer->memberMatch(...), $this->operations->findMembers($principal, $teamId, $name))]);
    }

    private function principal(Request $request): TaskApiPrincipal
    {
        return TaskApiRequestListener::principalOf($request);
    }
}
