<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
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
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function intdiv;
use function mb_strtolower;
use function str_contains;
use function trim;

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
    ) {
    }

    /**
     * `POST /v2/list/{id}/task`: a task, or a subtask with `parent`.
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
        $assignees  = $this->assignable($board, $input->strings('assignees'));
        $this->audit->requireTaskQuota($board, 1, $principal->user);

        $task = $this->tasks->create($board, $data, $principal->user, $parent);
        foreach ($assignees as $user) {
            $this->members->assign($task, new TaskMemberFormData($user, TaskMemberRole::Assignee), $principal->user);
        }
        $this->audit->written($principal, 'task.create', $board, $task->getId(), $parent !== null ? ['parent' => $parent->getId()] : []);

        return $task;
    }

    /**
     * `PUT /v2/task/{id}`. A *My tasks* token changes only status, dates and description; any other field is 403 `TOKEN_001`.
     *
     * @throws TaskApiRefusal
     */
    public function updateTask(TaskApiPrincipal $principal, string $taskId, TaskApiInput $input): Task
    {
        $this->audit->requireListener();
        $ownFieldsOnly = !$input->hasAny('name', 'priority', 'time_estimate', 'assignees', 'watchers', 'archived');
        $task          = $this->guard->task($principal, $taskId, $ownFieldsOnly ? TaskApiCapability::WorkOnTask : TaskApiCapability::EditTasks);
        $board         = $task->getBoard();

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
        $columns  = $board->getColumns()->toArray();
        $last     = $columns === [] ? null : $columns[array_key_last($columns)];
        $reopens  = $status instanceof BoardColumn && $task->isCompleted() && $last instanceof BoardColumn && $status->getId() !== $last->getId();
        $restores = $archived === false && $task->isArchived() && !$task->isCompleted();
        if ($this->tasks->activeTaskRefusal($board, (int) $reopens + (int) $restores) instanceof LimitRefusal) {
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        }

        // Both ask the limit again; it was asked above for both together.
        $changed = [];
        if ($archived === false && $this->withinPlan(fn (): bool => $this->tasks->restore($task, $principal->user))) {
            $changed[] = 'archived';
        }
        if ($status instanceof BoardColumn && $this->withinPlan(fn (): bool => $this->tasks->moveToColumn($task, $status->getId(), $principal->user))) {
            $changed[] = 'status';
        }
        // The column is where the move left it: update() must never move the task (it would skip completion and its events).
        $data->columnId = $task->getColumn()?->getId();
        if ($data != TaskFormData::fromTask($task)) {
            $this->withinPlan(fn () => $this->tasks->update($task, $data, $principal->user));
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
        $this->withinPlan(fn () => $this->tasks->update($task, $data, $principal->user));
        $this->audit->written($principal, $add ? 'tag.add' : 'tag.remove', $task->getBoard(), $taskId, [], ['tags']);

        return $task;
    }

    /**
     * `POST /v2/task/{id}/field/{field_id}`: custom fields are a provider's, read-only here (owner, Q-O3).
     *
     * @throws TaskApiRefusal
     */
    public function setField(TaskApiPrincipal $principal, string $taskId): never
    {
        $this->audit->requireListener();
        $this->guard->task($principal, $taskId, TaskApiCapability::EditTasks);

        throw new TaskApiRefusal(409, 'FIELD_001', 'Custom fields are read-only: a provider sync owns them');
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
        }
    }
}
