<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTemplates;
use Nowo\TaskBoardBundle\Template\Entity\TaskTemplate;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Template\TaskTemplateSnapshot;

use function array_map;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061/FR-062, `task_templates.enabled` with `api.enabled`): the task templates of a
 * board for a token — `GET /v2/list/{id}/taskTemplate` and the host's MCP `template_list(board_id)`. The board is reached
 * as for creating a task in it ({@see TaskApiCapability::EditTasks}); the templates are those
 * {@see TaskTemplateManager::usableOn()} offers the token's person. `template_id` on a new task is read by
 * {@see TaskApiTaskOperations::createTask()}.
 *
 * Stateless.
 */
final readonly class TaskApiTemplateOperations
{
    public function __construct(
        private TaskApiGuard $guard,
        private TaskTemplateManager $templates,
    ) {
    }

    /**
     * @throws TaskApiRefusal 404 for a board the token does not reach, 403 when it may not add tasks to it
     */
    public function templates(TaskApiPrincipal $principal, string $boardId): TaskApiTemplates
    {
        $board     = $this->guard->board($principal, $boardId, TaskApiCapability::EditTasks);
        $templates = $this->templates->usableOn($principal->user, $board);
        $columns   = [];
        foreach ($templates as $template) {
            $columns[$template->getId()] = $this->templates->defaultColumn($template, $board);
        }

        return new TaskApiTemplates($board, $templates, $columns);
    }

    /**
     * The same list as framework-free values, for an in-process caller (the host's MCP tools). A default column that no
     * longer exists on the board is given as null.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskTemplateSnapshot>
     */
    public function snapshots(TaskApiPrincipal $principal, string $boardId): array
    {
        return array_map(TaskTemplateSnapshot::of(...), $this->templates($principal, $boardId)->templates);
    }
}
