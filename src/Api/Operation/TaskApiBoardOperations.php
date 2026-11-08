<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskBoardEditor;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_map;
use function array_unique;
use function count;
use function sort;
use function trim;

/**
 * Since 2.2.0 (spec 213, Full management): boards and their columns, through the services the UI uses. Columns are not in
 * ClickUp's API: they are statuses here, with four documented paths of their own (owner, Q-O2). Every operation needs the
 * board's manage decision (`canManageBoard`), or the create check for a new board, and a `Full` token.
 */
final readonly class TaskApiBoardOperations
{
    /** ClickUp's list fields with no equivalent on a board. */
    public const NOT_SUPPORTED = ['due_date', 'due_date_time', 'priority', 'assignee', 'status', 'unset_status'];

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskBoardAccessCheckerInterface $accessChecker,
        private TaskBoardCreator $creator,
        private TaskBoardEditor $editor,
        private TaskBoardEraser $eraser,
        private BoardColumnManager $columns,
        private TaskApiQueryRepository $queries,
        private ValidatorInterface $validator,
        /** The tasks one `DELETE` erases per batch; the eraser stops at its time budget either way. */
        private int $eraseBatchSize = 200,
    ) {
    }

    /**
     * `POST /v2/space/{space_id}/list`: a board in the space's tenant, which the token must reach as a whole.
     *
     * @throws TaskApiRefusal
     */
    public function createBoard(TaskApiPrincipal $principal, string $spaceId, TaskApiInput $input): TaskBoard
    {
        $this->audit->requireListener();
        $space = $this->guard->space($principal, $spaceId, TaskApiCapability::ManageBoards);
        if (!$principal->where->reachesTenant($space->tenantKey)) {
            throw TaskApiRefusal::notFound();
        }
        $allowed = $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface
            ? $this->accessChecker->canCreateBoard($principal->user, $space->tenantKey)
            : $this->accessChecker->canCreateBoard($principal->user);
        if (!$allowed) {
            throw TaskApiRefusal::permissionRefused();
        }
        $data = new TaskBoardFormData(name: $this->text($input->string('name', true), TaskConstraints::boardName(), 'name'), description: $this->description($input));
        $this->audit->requireQuota(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, $space->tenantKey, null, $principal->user);

        $board = $this->creator->create($data, $principal->user, $space->tenantKey);
        $this->audit->written($principal, 'board.create', $board, null);

        return $board;
    }

    /**
     * `PUT /v2/list/{id}`: name and description; the slug stays.
     *
     * @throws TaskApiRefusal
     */
    public function updateBoard(TaskApiPrincipal $principal, string $boardId, TaskApiInput $input): TaskBoard
    {
        $this->audit->requireListener();
        $board       = $this->guard->board($principal, $boardId, TaskApiCapability::ManageBoards);
        $name        = $input->has('name') ? $this->text($input->string('name', true), TaskConstraints::boardName(), 'name') : $board->getName();
        $description = $input->hasAny('content', 'markdown_content') ? $this->description($input) : $board->getDescription();
        $this->editor->update($board, $name, $description, $principal->user);
        $this->audit->written($principal, 'board.update', $board, null);

        return $board;
    }

    /**
     * `DELETE /v2/list/{id}`: every task erased, tracked time kept, then the board. One call works within the eraser's time
     * budget; false when the client must call again (202). A board linked to a provider is refused: unlink it first.
     *
     * @throws TaskApiRefusal
     */
    public function deleteBoard(TaskApiPrincipal $principal, string $boardId): bool
    {
        $this->audit->requireListener();
        $board = $this->guard->board($principal, $boardId, TaskApiCapability::ManageBoards);
        if ($this->queries->hasConnection($board->getId())) {
            throw new TaskApiRefusal(409, 'LIST_002', 'The board follows a provider: unlink it first');
        }
        $done = $this->eraser->deleteBoard($board, $principal->user, $this->eraseBatchSize)->done;
        $this->audit->written($principal, $done ? 'board.delete' : 'board.delete_progress', $board, null);

        return $done;
    }

    /**
     * `POST /v2/list/{id}/status`: a column at the end. `status` is its name, `color` a hex colour.
     *
     * @throws TaskApiRefusal
     */
    public function createColumn(TaskApiPrincipal $principal, string $boardId, TaskApiInput $input): TaskBoard
    {
        $this->audit->requireListener();
        $board  = $this->guard->board($principal, $boardId, TaskApiCapability::ManageBoards);
        $column = $this->columns->add($board, $this->columnData($input, new BoardColumnFormData()));
        $this->audit->written($principal, 'column.create', $board, null, ['column' => $column->getId()]);

        return $board;
    }

    /**
     * `PUT /v2/list/{id}/status/{status_id}`.
     *
     * @throws TaskApiRefusal
     */
    public function updateColumn(TaskApiPrincipal $principal, string $boardId, string $columnId, TaskApiInput $input): TaskBoard
    {
        $this->audit->requireListener();
        $board  = $this->guard->board($principal, $boardId, TaskApiCapability::ManageBoards);
        $column = $this->column($board, $columnId);
        $this->columns->update($column, $this->columnData($input, new BoardColumnFormData($column->getName(), $column->getColor(), $column->getIcon())));
        $this->audit->written($principal, 'column.update', $board, null, ['column' => $columnId]);

        return $board;
    }

    /**
     * `DELETE /v2/list/{id}/status/{status_id}`: its tasks go to the first remaining column; the last column stays (409).
     *
     * @throws TaskApiRefusal
     */
    public function deleteColumn(TaskApiPrincipal $principal, string $boardId, string $columnId): TaskBoard
    {
        $this->audit->requireListener();
        $board  = $this->guard->board($principal, $boardId, TaskApiCapability::ManageBoards);
        $column = $this->column($board, $columnId);
        try {
            if (!$this->columns->delete($column)) {
                throw new TaskApiRefusal(409, 'LIST_001', 'A board keeps at least one column');
            }
        } catch (TaskReopenRefusedException) {
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        }
        $this->audit->written($principal, 'column.delete', $board, null, ['column' => $columnId]);

        return $board;
    }

    /**
     * `PUT /v2/list/{id}/status/order`: `order` lists every column id once.
     *
     * @throws TaskApiRefusal
     */
    public function reorderColumns(TaskApiPrincipal $principal, string $boardId, TaskApiInput $input): TaskBoard
    {
        $this->audit->requireListener();
        $board = $this->guard->board($principal, $boardId, TaskApiCapability::ManageBoards);
        $order = $input->strings('order');
        $given = array_unique($order);
        $known = array_map(static fn (BoardColumn $column): string => $column->getId(), $board->getColumns()->toArray());
        sort($given);
        sort($known);
        if (count($given) !== count($order) || $given !== $known) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid order: list every column once');
        }
        $this->columns->reorder($board, $order);
        $this->audit->written($principal, 'column.reorder', $board, null);

        return $board;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function column(TaskBoard $board, string $columnId): BoardColumn
    {
        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $columnId) {
                return $column;
            }
        }

        throw TaskApiRefusal::notFound();
    }

    /**
     * @throws TaskApiRefusal
     */
    private function columnData(TaskApiInput $input, BoardColumnFormData $data): BoardColumnFormData
    {
        if ($input->has('status') || $data->name === '') {
            $data->name = $this->text($input->string('status', true), TaskConstraints::columnName(), 'status');
        }
        if ($input->has('color')) {
            $color = $input->string('color');
            if ($color !== null && $this->validator->validate($color, TaskConstraints::columnColor())->count() > 0) {
                throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid color');
            }
            $data->color = $color;
        }

        return $data;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function description(TaskApiInput $input): ?string
    {
        return $input->has('markdown_content') ? $input->string('markdown_content') : $input->string('content');
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @throws TaskApiRefusal
     */
    private function text(?string $value, array $constraints, string $field): string
    {
        $value = trim((string) $value);
        if ($this->validator->validate($value, $constraints)->count() > 0) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . $field);
        }

        return $value;
    }
}
