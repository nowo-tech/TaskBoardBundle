<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiTemplateOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use Nowo\TaskBoardBundle\Template\TaskTemplateSnapshot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): `GET /v2/list/{id}/taskTemplate`, registered with `task_templates.enabled`.
 * Thin: one operation, then the shape of ClickUp's template list (`templates`, each with `id` and `name`) plus what a
 * template pre-fills.
 */
#[Route(requirements: TaskBoardRouteLoader::API_REQUIREMENTS, defaults: TaskBoardRouteLoader::API_DEFAULTS)]
final readonly class TaskApiTemplateController
{
    public function __construct(
        private TaskApiTemplateOperations $operations,
        private TaskApiReadSerializer $serializer,
    ) {
    }

    #[Route('/v2/list/{listId}/taskTemplate', name: 'nowo_task_board_api_task_templates', methods: ['GET'])]
    public function templates(Request $request, string $listId): JsonResponse
    {
        $list     = $this->operations->templates(TaskApiRequestListener::principalOf($request), $listId);
        $statuses = $this->serializer->statuses($list->board);

        return new JsonResponse(['templates' => array_map(static function (TaskTemplate $template) use ($list, $statuses): array {
            $column = $list->columns[$template->getId()] ?? null;
            $status = null;
            foreach ($statuses as $candidate) {
                if ($column !== null && ($candidate['id'] ?? null) === $column->getId()) {
                    $status = $candidate;
                }
            }

            return [
                'id'          => $template->getId(),
                'name'        => $template->getName(),
                'scope'       => $template->isBoardTemplate() ? TaskTemplateSnapshot::SCOPE_BOARD : TaskTemplateSnapshot::SCOPE_TENANT,
                'description' => (string) $template->getBody(),
                'tags'        => array_map(static fn (string $tag): array => ['name' => $tag, 'tag_fg' => null, 'tag_bg' => null], $template->getDefaultTags()),
                'status'      => $status,
            ];
        }, $list->templates)]);
    }
}
