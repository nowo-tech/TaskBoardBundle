<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\AuthorOnlyTaskApiCommentEditPolicy;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiCommentEditPolicyInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_filter;
use function array_key_first;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function intdiv;
use function is_string;
use function ksort;
use function mb_strtolower;
use function str_contains;
use function strcmp;
use function trim;
use function uasort;

/**
 * Since 2.2.0 (spec 213): the task writes of the API, through the services the UI uses (FR-011), without HTTP. Each method
 * checks the token through the guard, validates with the forms' rules, checks everything it can before the first write, and
 * ends with one `API_WRITE`.
 *
 * A write that changes several things saves each with its own service, as the UI's separate forms do. What can be refused
 * is asked first (assignable people, status, dates, limits), so a refusal changes nothing; a failure of the database between
 * two saves can leave the first one.
 */
final readonly class TaskApiTaskOperations
{
    /** What a *My tasks* token may change on its own task (owner, FR-012). */
    public const OWN_TASK_FIELDS = ['status', 'description', 'markdown_content', 'due_date', 'due_date_time', 'start_date', 'start_date_time'];

    /** ClickUp's task fields Beacon has no equivalent for: warned, never saved. */
    public const NOT_SUPPORTED = ['tags', 'points', 'group_assignees', 'custom_item_id', 'custom_fields', 'notify_all', 'links_to', 'check_required_custom_fields', 'parent', 'due_date_time', 'start_date_time', 'custom_task_ids', 'team_id'];

    /** Since 2.16.0 (spec 234, FR-021): at most this many tasks in one bulk update. */
    public const BULK_MAX = 50;

    /** The task form's tag field takes at most 20. */
    public const MAX_TAGS = 20;

    private const PRIORITIES = [1 => TaskPriority::Urgent, 2 => TaskPriority::High, 3 => TaskPriority::Normal, 4 => TaskPriority::Low];

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskApiUsersInterface $users,
        private TaskManager $tasks,
        private TaskMemberAssigner $members,
        private TaskBoardEraser $eraser,
        private TaskDependencyManager $dependencies,
        private ValidatorInterface $validator,
        private TaskCommentManager $comments,
        private TaskMentionCandidatesResolverInterface $mentionCandidates,
        private TaskApiAttachmentsInterface $attachments,
        // Since 2.16.0 (spec 234): the transaction of an update with a base revision.
        private TaskApiTransaction $transaction,
        // Since 2.16.0 (spec 234): `PUT /v2/comment/{id}`.
        private TaskCommentRepositoryInterface $commentRepository,
        private TaskApiCommentEditPolicyInterface $commentEdits = new AuthorOnlyTaskApiCommentEditPolicy(),
        // Since 2.16.0 (Beacon spec 234, FR-061): with `task_templates.enabled`, `template_id` on a new task.
        private ?TaskTemplateManager $templates = null,
        // Since 2.19.0 (Beacon spec 234, FR-028): native custom fields.
        private ?TaskCustomFieldManager $customFields = null,
    ) {
    }

    /**
     * Since 2.16.0 (spec 234): how a write of this principal enters a column: its channel as it is (`api`, `mcp` or a
     * host's own), never `web`.
     */
    public static function via(TaskApiPrincipal $principal, string $operation = ColumnTransitionVia::OP_MOVE): ColumnTransitionVia
    {
        return new ColumnTransitionVia($principal->channel, $operation);
    }

    /**
     * `POST /v2/list/{id}/task`: a task, or a subtask with `parent`. Since 2.16.0 (`task_templates.enabled`), `template_id`
     * pre-fills the description, the tags and the status from a template the board offers the token's person; a field
     * the body sets wins over the template's ({@see self::applyTemplate()}). The column it leaves in `TaskFormData` goes
     * through the same seam an explicit `status` does, so the column transition policy decides on it too (a template
     * whose column is *humans only* is refused with that policy's code, as any other status would be).
     *
     * @throws TaskApiRefusal
     */
    public function createTask(TaskApiPrincipal $principal, string $boardId, TaskApiInput $input): Task
    {
        $this->audit->requireListener();
        $board    = $this->guard->board($principal, $boardId, TaskApiCapability::EditTasks);
        $parent   = null;
        $parentId = $input->string('parent');
        if ($parentId !== null) {
            $parent = $this->guard->task($principal, $parentId, TaskApiCapability::EditTasks);
            if ($parent->getBoard()->getId() !== $board->getId()) {
                throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid parent');
            }
        }

        $data           = new TaskFormData(title: $this->title($input->string('name', true)));
        $data->columnId = $this->columnOf($board, $input->string('status'))?->getId();
        $this->applyFields($data, $input);
        $data->tags = $this->tags($input->strings('tags'));
        $template   = $this->applyTemplate($principal, $board, $data, $input);
        $assignees  = $this->assignable($board, $input->strings('assignees'));
        $this->audit->requireTaskQuota($board, 1, $principal->user);

        $task = $this->withinPlan(fn (): Task => $this->tasks->create($board, $data, $principal->user, $parent, null, self::via($principal, ColumnTransitionVia::OP_CREATE)));
        foreach ($assignees as $user) {
            $this->members->assign($task, new TaskMemberFormData($user, TaskMemberRole::Assignee), $principal->user);
        }
        $targets = $parent !== null ? ['parent' => $parent->getId()] : [];
        if ($template !== null) {
            $targets['template'] = $template;
        }
        $this->audit->written($principal, 'task.create', $board, $task->getId(), $targets);

        return $task;
    }

    /**
     * `PUT /v2/task/{id}`. A *My tasks* token changes only status, dates and description; any other field is 403 `TOKEN_001`.
     *
     * Since 2.16.0 (spec 234, FR-018): with a base revision (`$ifMatch`, the `If-Match` header, else `base_revision` in the
     * body) the task's row is locked and compared first: another revision is 409 `TASK_409` with `current_revision`, and
     * nothing is written. The whole update is then one transaction.
     *
     * @throws TaskApiRefusal
     */
    public function updateTask(TaskApiPrincipal $principal, string $taskId, TaskApiInput $input, ?int $ifMatch = null): Task
    {
        $this->audit->requireListener();
        $ownFieldsOnly = !$input->hasAny('name', 'priority', 'time_estimate', 'assignees', 'watchers', 'archived');
        $base          = $ifMatch ?? self::baseRevision($input);
        $task          = $this->guard->task($principal, $taskId, $ownFieldsOnly ? TaskApiCapability::WorkOnTask : TaskApiCapability::EditTasks);
        if ($base === null) {
            return $this->applyUpdate($principal, $task, $input);
        }

        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        return $this->transaction->run(function () use ($principal, $task, $input, $base): Task {
            $current = $this->transaction->lockedRevision($task);
            if ($current !== $base) {
                throw TaskApiRefusal::stale($current);
            }

            return $this->applyUpdate($principal, $task, $input);
        }, [$task]);
    }

    /**
     * Since 2.16.0: `base_revision` of a body: a positive integer, or none.
     *
     * @throws TaskApiRefusal
     */
    public static function baseRevision(TaskApiInput $input): ?int
    {
        if (!$input->has('base_revision')) {
            return null;
        }
        $base = $input->int('base_revision');
        if ($base === null || $base < 1) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid base_revision');
        }

        return $base;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function applyUpdate(TaskApiPrincipal $principal, Task $task, TaskApiInput $input): Task
    {
        $board = $task->getBoard();

        // Everything that can be refused, before the first save.
        $data = TaskFormData::fromTask($task);
        // The column moves only through moveToColumn(), with completion, the reopen limit and their events.
        $data->columnId = $task->getColumn()?->getId();
        if ($input->has('name')) {
            $data->title = $this->title($input->string('name', true));
        }
        $this->applyFields($data, $input);
        $status    = $input->has('status') ? $this->columnOf($board, $input->string('status', true)) : null;
        $assignees = $input->addRemove('assignees');
        $watchers  = $input->addRemove('watchers');
        $add       = [
            TaskMemberRole::Assignee->value => $this->assignable($board, $assignees['add']),
            TaskMemberRole::Watcher->value  => $this->assignable($board, $watchers['add']),
        ];
        $archived = $input->bool('archived');
        // Audit IA-L6: every limit before the first save. A restored task that was open, and a done one leaving the done
        // column, each add an active task.
        // Since 2.19.0 (FR-033): leaving the done columns (column type; the last column on an untyped board).
        $reopens  = $status instanceof BoardColumn && $task->isCompleted() && !ColumnTypes::isDone($status);
        $restores = $archived === false && $task->isArchived() && !$task->isCompleted();
        if ($this->tasks->activeTaskRefusal($board, (int) $reopens + (int) $restores) instanceof LimitRefusal) {
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        }

        // Both ask the limit again; it was asked above for both together.
        $changed = [];
        if ($archived === false && $this->withinPlan(fn (): bool => $this->tasks->restore($task, $principal->user))) {
            $changed[] = 'archived';
        }
        if ($status instanceof BoardColumn && $this->withinPlan(fn (): bool => $this->tasks->moveToColumn($task, $status->getId(), $principal->user, self::via($principal)))) {
            $changed[] = 'status';
        }
        // The column is where the move left it: update() must never move the task (it would skip completion and its events).
        $data->columnId = $task->getColumn()?->getId();
        if ($data != TaskFormData::fromTask($task)) {
            $this->withinPlan(fn () => $this->tasks->update($task, $data, $principal->user, self::via($principal)));
            $changed[] = 'fields';
        }
        foreach ([TaskMemberRole::Assignee, TaskMemberRole::Watcher] as $role) {
            foreach ($add[$role->value] as $user) {
                $this->members->assign($task, new TaskMemberFormData($user, $role), $principal->user);
                $changed[] = $role->value . 's';
            }
            foreach (($role === TaskMemberRole::Assignee ? $assignees : $watchers)['rem'] as $apiId) {
                foreach ($task->getMembers() as $member) {
                    if ($member->getMemberRole() === $role && $this->users->apiId($member->getUser()) === $apiId) {
                        $this->members->unassign($task, $member->getId(), $principal->user);
                        $changed[] = $role->value . 's';
                    }
                }
            }
        }
        if ($archived === true && $this->tasks->archive($task, $principal->user)) {
            $changed[] = 'archived';
        }
        $this->audit->written($principal, 'task.update', $board, $task->getId(), [], array_values(array_unique($changed)));

        return $task;
    }

    /**
     * Since 2.16.0 (spec 234, FR-021): `POST /v2/task/bulk`, `{"tasks": [{"id": …, "base_revision"?: n, "description" |
     * "markdown_content", "status", "tags" (the whole list), "priority", "due_date", "start_date", "assignees": {"add",
     * "rem"}}, …]}`: at most {@see self::BULK_MAX} tasks of one space (tenant), all or none.
     *
     * Every item is checked as its own `PUT /v2/task/{id}` would be (reach, token level, values, the column transition
     * policy with operation `bulk`, the host's limit) and, inside one transaction, its row is locked (in id order) and its
     * `base_revision` compared. Any refusal writes nothing and answers `BULK_001` with every failing item (`failures`:
     * index, id, status, ECODE, err, and `current_revision` for `TASK_409`), always with HTTP 409 (Beacon review 2026-11-13):
     * each item's own status and ECODE stay in `failures`. Since audit PC-L1 a refusal that only appears while writing (a
     * stateful host rule refusing an item once the previous ones are written) answers the same way, with that one item,
     * and every write of the request is rolled back.
     * After the commit: one `API_WRITE` (`task.bulk_update`) per task and one `API_BULK_WRITE` with the count.
     *
     * @throws TaskApiRefusal
     *
     * @return list<Task> in the order of the request
     */
    public function updateMany(TaskApiPrincipal $principal, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        $items = $input->objects('tasks');
        if ($items === []) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid tasks: at least one');
        }
        if (count($items) > self::BULK_MAX) {
            throw new TaskApiRefusal(400, 'BULK_003', 'At most ' . self::BULK_MAX . ' tasks in one bulk update');
        }

        // 1. Reach, as each update would ask it; nothing is locked or written yet.
        $failures = [];
        $tasks    = [];
        $inputs   = [];
        $bases    = [];
        $seen     = [];
        foreach ($items as $index => $fields) {
            $item           = new TaskApiInput($fields, self::NOT_SUPPORTED);
            $inputs[$index] = $item;
            try {
                $id = (string) $item->string('id', true);
                if (isset($seen[$id])) {
                    throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid id: twice in one bulk update');
                }
                $seen[$id]     = true;
                $bases[$index] = self::baseRevision($item);
                $ownFieldsOnly = !$item->hasAny('priority', 'time_estimate', 'assignees', 'tags');
                $tasks[$index] = $this->guard->task($principal, $id, $ownFieldsOnly ? TaskApiCapability::WorkOnTask : TaskApiCapability::EditTasks);
            } catch (TaskApiRefusal $refusal) {
                $failures[$index] = self::failure($index, $fields['id'] ?? null, $refusal);
            }
        }
        if ($failures !== []) {
            throw self::bulkRefused($failures);
        }
        // Since 2.15.1 (REQ-SEC-006): the keys are compared byte for byte, never through PHP's array keys, so a
        // look-alike tenant (`Acme` next to `acme`, a null next to an empty key) is a second space, not the same one.
        $tenant = null;
        foreach ($tasks as $position => $task) {
            $key = $task->getBoard()->getTenantKey();
            if ($position === array_key_first($tasks)) {
                $tenant = $key;
            } elseif ($key !== $tenant) {
                throw new TaskApiRefusal(400, 'BULK_002', 'A bulk update stays within one space');
            }
        }

        // 2. One transaction: lock, compare, check everything, then write.
        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        $changed = $this->transaction->run(function () use ($principal, $tasks, $inputs, $bases): array {
            $ordered = $tasks;
            uasort($ordered, static fn (Task $a, Task $b): int => strcmp($a->getId(), $b->getId()));
            $failures = [];
            foreach ($ordered as $index => $task) {
                $current = $this->transaction->lockedRevision($task);
                if ($bases[$index] !== null && $bases[$index] !== $current) {
                    $failures[$index] = self::failure($index, $task->getId(), TaskApiRefusal::stale($current));
                }
            }
            $plans = [];
            foreach ($tasks as $index => $task) {
                if (isset($failures[$index])) {
                    continue;
                }
                try {
                    $plans[$index] = $this->bulkPlan($principal, $task, $inputs[$index]);
                } catch (TaskApiRefusal $refusal) {
                    $failures[$index] = self::failure($index, $task->getId(), $refusal);
                }
            }
            $failures += $this->bulkLimits($tasks, $plans);
            if ($failures !== []) {
                throw self::bulkRefused($failures);
            }
            $changed = [];
            foreach ($plans as $index => $plan) {
                try {
                    $changed[$index] = $this->bulkWrite($principal, $tasks[$index], $plan);
                } catch (TaskApiRefusal $refusal) {
                    // Audit PC-L1: a refusal decided while writing (a host rule that counts the items written before
                    // this one) is still `BULK_001` with its item in `failures`; run() rolls every write back.
                    throw self::bulkRefused([$index => self::failure($index, $tasks[$index]->getId(), $refusal)]);
                }
            }

            return $changed;
        }, array_values($tasks));

        foreach ($tasks as $index => $task) {
            $this->audit->written($principal, 'task.bulk_update', $task->getBoard(), $task->getId(), [], $changed[$index] ?? []);
        }
        $this->audit->bulkWritten($principal, $tenant, array_values(array_map(static fn (Task $task): string => $task->getId(), $tasks)));

        return array_values($tasks);
    }

    /**
     * What one bulk item changes, checked: nothing is written here.
     *
     * @throws TaskApiRefusal
     *
     * @return array{data: TaskFormData, status: BoardColumn|null, add: list<object>, rem: list<string>}
     */
    private function bulkPlan(TaskApiPrincipal $principal, Task $task, TaskApiInput $item): array
    {
        $board          = $task->getBoard();
        $data           = TaskFormData::fromTask($task);
        $data->columnId = $task->getColumn()?->getId();
        $this->applyFields($data, $item);
        if ($item->has('tags')) {
            $data->tags = $this->tags($item->strings('tags'));
        }
        $status = $item->has('status') ? $this->columnOf($board, $item->string('status', true)) : null;
        if ($status instanceof BoardColumn) {
            try {
                $this->tasks->assertCanEnter($task, $status, $principal->user, self::via($principal, ColumnTransitionVia::OP_BULK), $data->tags);
            } catch (ColumnTransitionRefusedException $refused) {
                throw TaskApiRefusal::transitionRefused($refused);
            }
        }
        $assignees = $item->addRemove('assignees');
        // A field a bulk item does not take is refused, never silently skipped (an agent would believe it saved).
        foreach ($item->warnings() as $warning) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . $warning['field'] . ': not taken by a bulk update');
        }

        return ['data' => $data, 'status' => $status, 'add' => $this->assignable($board, $assignees['add']), 'rem' => $assignees['rem']];
    }

    /**
     * The host's limit of active tasks, per board, on every completed task a bulk update reopens (audit IA-L6).
     *
     * @param array<int, Task> $tasks
     * @param array<int, array{data: TaskFormData, status: BoardColumn|null, add: list<object>, rem: list<string>}> $plans
     *
     * @return array<int, array<string, mixed>>
     */
    private function bulkLimits(array $tasks, array $plans): array
    {
        $reopens = [];
        foreach ($plans as $index => $plan) {
            $task = $tasks[$index];
            if ($plan['status'] instanceof BoardColumn && $task->isCompleted() && !ColumnTypes::isDone($plan['status'])) {
                $reopens[$task->getBoard()->getId()][] = $index;
            }
        }
        $failures = [];
        foreach ($reopens as $indexes) {
            $board = $tasks[$indexes[0]]->getBoard();
            if ($this->tasks->activeTaskRefusal($board, count($indexes)) instanceof LimitRefusal) {
                foreach ($indexes as $index) {
                    $failures[$index] = self::failure($index, $tasks[$index]->getId(), new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached'));
                }
            }
        }

        return $failures;
    }

    /**
     * @param array{data: TaskFormData, status: BoardColumn|null, add: list<object>, rem: list<string>} $plan
     *
     * @throws TaskApiRefusal
     *
     * @return list<string> what changed
     */
    private function bulkWrite(TaskApiPrincipal $principal, Task $task, array $plan): array
    {
        $via     = self::via($principal, ColumnTransitionVia::OP_BULK);
        $changed = [];
        // The fields first (the column stays), so the move is asked with the tags the item gives the task, as checked.
        $data           = $plan['data'];
        $data->columnId = $task->getColumn()?->getId();
        if ($data != TaskFormData::fromTask($task)) {
            $this->withinPlan(fn () => $this->tasks->update($task, $data, $principal->user, $via));
            $changed[] = 'fields';
        }
        $status = $plan['status'];
        if ($status instanceof BoardColumn && $this->withinPlan(fn (): bool => $this->tasks->moveToColumn($task, $status->getId(), $principal->user, $via))) {
            $changed[] = 'status';
        }
        foreach ($plan['add'] as $user) {
            $this->members->assign($task, new TaskMemberFormData($user, TaskMemberRole::Assignee), $principal->user);
            $changed[] = 'assignees';
        }
        foreach ($plan['rem'] as $apiId) {
            foreach ($task->getMembers() as $member) {
                if ($member->getMemberRole() === TaskMemberRole::Assignee && $this->users->apiId($member->getUser()) === $apiId) {
                    $this->members->unassign($task, $member->getId(), $principal->user);
                    $changed[] = 'assignees';
                }
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(int $index, mixed $id, TaskApiRefusal $refusal): array
    {
        return ['index' => $index, 'id' => is_string($id) ? $id : null, 'status' => $refusal->status, 'ECODE' => $refusal->ecode, 'err' => $refusal->getMessage()] + $refusal->extra;
    }

    /**
     * @param array<int, array<string, mixed>> $failures by index
     */
    private static function bulkRefused(array $failures): TaskApiRefusal
    {
        ksort($failures);

        return new TaskApiRefusal(409, 'BULK_001', 'Bulk update refused: nothing was changed', ['failures' => array_values($failures)]);
    }

    /**
     * `DELETE /v2/task/{id}`: erased with its subtasks, tracked time kept (owner, Q-O5).
     *
     * @throws TaskApiRefusal
     */
    public function deleteTask(TaskApiPrincipal $principal, string $taskId): TaskBoard
    {
        $this->audit->requireListener();
        $task  = $this->guard->task($principal, $taskId, TaskApiCapability::DeleteTask);
        $board = $task->getBoard();
        try {
            $this->eraser->eraseTask($task, $principal->user);
        } catch (TaskLinkedToProviderException) {
            throw new TaskApiRefusal(409, 'TASK_002', 'The task follows a provider: unlink its board first');
        }
        $this->audit->written($principal, 'task.delete', $board, $taskId);

        return $board;
    }

    /**
     * `POST /v2/task/{id}/comment`. Mentions work as in the UI's box: each candidate written "@Label" in the text is mentioned,
     * so `TASK_MENTION` and the host's notifications follow. ClickUp's structured mention fields are not read.
     *
     * @throws TaskApiRefusal
     */
    public function addComment(TaskApiPrincipal $principal, string $taskId, TaskApiInput $input): TaskComment
    {
        $this->audit->requireListener();
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::Comment);
        $body = (string) $input->string('comment_text', true);
        $this->validate($body, TaskConstraints::commentBody(), 'comment_text');
        $mentioned = [];
        foreach ($this->mentionCandidates->candidates($task) as $candidate) {
            if (str_contains($body, '@' . $candidate->label)) {
                $mentioned[] = $candidate->id;
            }
        }
        // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
        $comment = $this->comments->add($task, $principal->user, $body, $mentioned);
        $this->audit->written($principal, 'comment.create', $task->getBoard(), $taskId, ['comment' => $comment->getId()]);

        return $comment;
    }

    /**
     * Since 2.16.0 (spec 234): `PUT /v2/comment/{id}` (`comment_text`). The comment's task must be one the token may
     * comment on (its tenant and reach, as `POST /v2/task/{id}/comment`), then `api.hooks.comment_edit_policy` decides
     * (default: its author only). A comment that does not exist, is deleted, is out of reach or may not be edited is
     * 404 alike, so nothing tells them apart. Mentions work as on create.
     *
     * @throws TaskApiRefusal
     */
    public function editComment(TaskApiPrincipal $principal, string $commentId, TaskApiInput $input): TaskComment
    {
        $this->audit->requireListener();
        $comment = $this->commentRepository->findById($commentId);
        if (!$comment instanceof TaskComment || $comment->getId() !== $commentId || $comment->isDeleted()) {
            throw TaskApiRefusal::notFound();
        }
        try {
            $task = $this->guard->task($principal, $comment->getTask()->getId(), TaskApiCapability::Comment);
        } catch (TaskApiRefusal) {
            throw TaskApiRefusal::notFound();
        }
        if (!$this->commentEdits->mayEdit($principal, Snapshots::comment($comment))) {
            throw TaskApiRefusal::notFound();
        }
        $body = (string) $input->string('comment_text', true);
        $this->validate($body, TaskConstraints::commentBody(), 'comment_text');
        $mentioned = [];
        foreach ($this->mentionCandidates->candidates($task) as $candidate) {
            if (str_contains($body, '@' . $candidate->label)) {
                $mentioned[] = $candidate->id;
            }
        }
        // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
        $this->comments->edit($comment, $principal->user, $body, $mentioned);
        $this->audit->written($principal, 'comment.update', $task->getBoard(), $task->getId(), ['comment' => $comment->getId()]);

        return $comment;
    }

    /**
     * `POST /v2/task/{id}/attachment` (multipart, field `attachment`): stored by the host, with all its upload checks (FR-014).
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: TaskApiAttachment}
     */
    public function addAttachment(TaskApiPrincipal $principal, string $taskId, ?UploadedFile $file): array
    {
        $this->audit->requireListener();
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::EditTasks);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid attachment');
        }
        $attachment = $this->attachments->store($principal, $task, $file);
        $this->audit->written($principal, 'attachment.create', $task->getBoard(), $taskId, ['attachment' => $attachment->id]);

        return [$task, $attachment];
    }

    /**
     * `POST` and `DELETE /v2/task/{id}/tag/{name}`.
     *
     * @throws TaskApiRefusal
     */
    public function tag(TaskApiPrincipal $principal, string $taskId, string $name, bool $add): Task
    {
        $this->audit->requireListener();
        $task       = $this->guard->task($principal, $taskId, TaskApiCapability::EditTasks);
        $name       = trim($name);
        $data       = TaskFormData::fromTask($task);
        $data->tags = $add
            ? $this->tags([...$task->getTags(), $name])
            : array_values(array_filter($task->getTags(), static fn (string $tag): bool => mb_strtolower($tag) !== mb_strtolower($name)));
        $this->withinPlan(fn () => $this->tasks->update($task, $data, $principal->user, self::via($principal)));
        $this->audit->written($principal, $add ? 'tag.add' : 'tag.remove', $task->getBoard(), $taskId, [], ['tags']);

        return $task;
    }

    /**
     * `POST /v2/task/{id}/field/{field_id}`: custom fields are a provider's, read-only here (owner, Q-O3).
     *
     * @throws TaskApiRefusal
     */
    public function setField(TaskApiPrincipal $principal, string $taskId, string $fieldId = '', ?TaskApiInput $input = null): Task
    {
        $this->audit->requireListener();
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::EditTasks);
        // Since 2.19.0 (FR-028): a native field of the task's board is writable; any other (a provider's) stays 409.
        $field = $this->customFields?->fieldOfBoard($task->getBoard(), $fieldId);
        if ($field === null || !$input instanceof TaskApiInput) {
            throw new TaskApiRefusal(409, 'FIELD_001', 'Custom fields are read-only: a provider sync owns them');
        }
        if (!$input->has('value')) {
            throw new TaskApiRefusal(400, 'INPUT_001', 'Missing value');
        }
        try {
            $this->customFields?->setField($task, $field->getId(), $input->raw('value'), $principal->user);
        } catch (TaskCustomFieldRefusedException) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid value');
        }
        $this->audit->written($principal, 'field.set', $task->getBoard(), $taskId, ['field' => $field->getId()], ['custom_fields']);

        return $task;
    }

    /**
     * `POST /v2/task/{id}/dependency` (`depends_on` or `dependency_of`) and `POST /v2/task/{id}/link/{links_to}`.
     *
     * @throws TaskApiRefusal
     */
    public function relate(TaskApiPrincipal $principal, string $taskId, string $otherId, TaskDependencyType $type): Task
    {
        $this->audit->requireListener();
        $task  = $this->guard->task($principal, $taskId, TaskApiCapability::EditTasks);
        $other = $this->guard->task($principal, $otherId, TaskApiCapability::ReadOwn);
        try {
            // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
            $this->dependencies->add($task, $other, $type, $principal->user);
        } catch (InvalidTaskDependencyException $invalid) {
            throw $invalid->reason === InvalidTaskDependencyException::REASON_OTHER_BOARD ? new TaskApiRefusal(400, 'LINK_002', 'Dependencies stay within a board') : new TaskApiRefusal(400, 'LINK_001', 'This dependency is not allowed');
        }
        $this->audit->written($principal, $type === TaskDependencyType::Related ? 'link.add' : 'dependency.add', $task->getBoard(), $taskId, ['other' => $other->getId()]);

        return $task;
    }

    /**
     * `DELETE /v2/task/{id}/dependency` and `DELETE /v2/task/{id}/link/{links_to}`: the relations of these types between
     * the two tasks.
     *
     * @param list<TaskDependencyType> $types
     *
     * @throws TaskApiRefusal
     */
    public function unrelate(TaskApiPrincipal $principal, string $taskId, string $otherId, array $types, ?bool $taskWaits): Task
    {
        $this->audit->requireListener();
        $task    = $this->guard->task($principal, $taskId, TaskApiCapability::EditTasks);
        $other   = $this->guard->task($principal, $otherId, TaskApiCapability::ReadOwn);
        $removed = 0;
        foreach ($this->dependencies->findBetween($task, $other) as $dependency) {
            $type = $dependency->getDependencyType();
            if (!in_array($type, $types, true) || ($taskWaits !== null && $this->waits($task, $dependency->getSourceTask()->getId() === $task->getId(), $type) !== $taskWaits)) {
                continue;
            }
            // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
            $this->dependencies->remove($task, $dependency->getId(), $principal->user);
            ++$removed;
        }
        if ($removed === 0) {
            throw TaskApiRefusal::notFound();
        }
        $this->audit->written($principal, in_array(TaskDependencyType::Related, $types, true) ? 'link.remove' : 'dependency.remove', $task->getBoard(), $taskId, ['other' => $other->getId()]);

        return $task;
    }

    /** Whether, in this relation, the task waits on the other one (ClickUp's `depends_on`). */
    private function waits(Task $task, bool $taskIsSource, TaskDependencyType $type): bool
    {
        return $taskIsSource ? $type === TaskDependencyType::BlockedBy : $type === TaskDependencyType::Blocks;
    }

    /**
     * Description, priority, dates and estimate, as the task form takes them.
     *
     * @throws TaskApiRefusal
     */
    private function applyFields(TaskFormData $data, TaskApiInput $input): void
    {
        foreach (['markdown_content', 'description'] as $field) {
            if ($input->has($field)) {
                $data->description = $input->string($field);

                break;
            }
        }
        if ($input->has('priority')) {
            $priority       = $input->int('priority');
            $data->priority = $priority === null ? TaskPriority::Normal : (self::PRIORITIES[$priority] ?? throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid priority'));
        }
        if ($input->has('due_date')) {
            $data->dueAt = $input->date('due_date');
        }
        if ($input->has('start_date')) {
            $data->startAt = $input->date('start_date');
        }
        if ($input->has('time_estimate')) {
            $estimate               = $input->int('time_estimate');
            $data->estimatedMinutes = $estimate === null ? null : intdiv($estimate, 60000);
        }
        if (TaskConstraints::startsAfterDue($data->startAt, $data->dueAt)) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid start_date: after the due date');
        }
    }

    /**
     * Since 2.16.0 (Beacon spec 234, FR-061): `template_id`, read only with `task_templates.enabled` (else it is an
     * unknown field, warned). The template must be one the board offers the person ({@see TaskTemplateManager::usable()});
     * any other id — unknown, of another board or tenant, or not allowed — is the same 400, so nothing about it leaks.
     * Its description, tags and default column fill only what the body does not set (`markdown_content` / `description`,
     * `tags`, `status`). The column goes through the same create path as a `status` (seam for a transition policy).
     *
     * @throws TaskApiRefusal
     *
     * @return string|null the template's id
     */
    private function applyTemplate(TaskApiPrincipal $principal, TaskBoard $board, TaskFormData $data, TaskApiInput $input): ?string
    {
        if (!$this->templates instanceof TaskTemplateManager) {
            return null;
        }
        $id = $input->string('template_id');
        if ($id === null) {
            return null;
        }
        $template = $this->templates->usable($principal->user, $board, $id);
        if ($template === null) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid template_id');
        }
        $this->templates->prefill(
            $template,
            $board,
            $data,
            description: !$input->hasAny('markdown_content', 'description'),
            tags: !$input->has('tags'),
            column: !$input->has('status'),
        );

        return $template->getId();
    }

    /**
     * A board's column by its name, case aside (ClickUp's status).
     *
     * @throws TaskApiRefusal
     */
    private function columnOf(TaskBoard $board, ?string $status): ?BoardColumn
    {
        if ($status === null) {
            return null;
        }
        foreach ($board->getColumns() as $column) {
            if (mb_strtolower($column->getName()) === mb_strtolower($status)) {
                return $column;
            }
        }

        throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid status');
    }

    /**
     * The people named, each one the board's assignee rule allows.
     *
     * @param list<string> $apiIds
     *
     * @throws TaskApiRefusal
     *
     * @return list<object>
     */
    private function assignable(TaskBoard $board, array $apiIds): array
    {
        $allowed = $this->members->assignableUsers($board);
        $users   = [];
        foreach ($apiIds as $apiId) {
            $user = $this->users->find($apiId);
            if ($user === null || ($allowed !== null && !in_array($user, $allowed, true))) {
                throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid assignee');
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * The task form's rules for tags: each a valid name, at most {@see self::MAX_TAGS}.
     *
     * @param list<string> $tags
     *
     * @throws TaskApiRefusal
     *
     * @return list<string>
     */
    private function tags(array $tags): array
    {
        $tags = array_values(array_unique(array_map('trim', $tags)));
        foreach ($tags as $tag) {
            $this->validate($tag, TaskConstraints::tagName(), 'tags');
        }
        if (count($tags) > self::MAX_TAGS) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid tags: at most ' . self::MAX_TAGS);
        }

        return $tags;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function title(?string $name): string
    {
        $title = trim((string) $name);
        $this->validate($title, TaskConstraints::taskTitle(), 'name');

        return $title;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @throws TaskApiRefusal
     */
    private function validate(mixed $value, array $constraints, string $field): void
    {
        if ($this->validator->validate($value, $constraints)->count() > 0) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . $field);
        }
    }

    /**
     * Since 2.2.1 (audit IB-L1): the host's limit refusing a write after the checks above (it changed in between) is 403
     * `PLAN_002`, never a 500.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @throws TaskApiRefusal
     *
     * @return T
     */
    private function withinPlan(callable $write): mixed
    {
        try {
            return $write();
        } catch (TaskReopenRefusedException) {
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        } catch (ColumnTransitionRefusedException $refused) {
            // Since 2.16.0 (spec 234, FR-015 / FR-017).
            throw TaskApiRefusal::transitionRefused($refused);
        }
    }
}
