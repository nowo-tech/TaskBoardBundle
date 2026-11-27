<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiAudit;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiChecklistOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTransaction;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientSourceLinkInterface;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSourceLink;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Service\OrderedRowLockInterface;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_key_exists;
use function array_map;
use function array_search;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function trim;

/**
 * Since 3.4.0 (client API v1, §4.3 of the Beacon Desktop tasks design): the core writes of `/task-board/v1` — quick
 * create, field changes, moves, members and checklist items — through the services the UI and the Task API use: the
 * guard (reach, plan, level, the person's right), the form rules (`TaskConstraints`), `TaskManager` (the column
 * transition policy with channel `app`, completion, the reopen limit, revisions, history and events),
 * `TaskMemberAssigner`, `TaskApiChecklistOperations` and the audit (`API_WRITE` with channel `app`). It adds no second
 * copy of a rule.
 *
 * REQ-RUNTIME-003: a change of an existing task runs in one short transaction ({@see TaskApiTransaction}: the lost-savepoint
 * rollback to the entry level, the connection closed when that rollback fails, the original exception rethrown, the
 * events held until the commit). The column it enters is locked before the task's row (the fixed order of the Task API),
 * then the revision the client sent (`If-Match`) is compared on the locked row: another one is `409 conflict` and
 * nothing is written. No I/O under the locks (the host's source link is asked before). A reorder inside one column is
 * last-writer-wins by design (no revision bump), as the web board's drag and drop. Stateless (worker mode).
 */
final readonly class TaskClientWriter
{
    /** The fields `PATCH tasks/{id}` takes; any other is `400 invalid_field`. */
    public const PATCH_FIELDS = ['title', 'description', 'priority', 'color', 'tags', 'dueAt', 'startAt', 'allDay', 'estimateMinutes', 'archived'];

    /** The fields `POST tasks` takes. */
    public const CREATE_FIELDS = ['boardId', 'columnId', 'title', 'description', 'priority', 'dueAt', 'startAt', 'allDay', 'estimateMinutes', 'tags', 'assignees', 'parentId', 'templateId', 'source'];

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskApiTransaction $transaction,
        private TaskManager $tasks,
        private TaskMemberAssigner $members,
        private TaskApiUsersInterface $users,
        private ValidatorInterface $validator,
        private TaskApiChecklistOperations $checklists,
        private TaskClientQueries $queries,
        private TaskRepositoryInterface $taskRepository,
        private ?OrderedRowLockInterface $rowLock = null,
        private ?TaskClientSourceLinkInterface $sources = null,
        private ?TaskLinkAttacher $links = null,
    ) {
    }

    /**
     * `POST tasks`: a task in a column of the board (the first open one by default), with its assignees, in one
     * transaction; a `source` linked to the host object the host's port answers.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public function create(TaskClientCall $call, array $body): Task
    {
        TaskClientInput::only($body, self::CREATE_FIELDS);
        $principal = $call->principal;
        $this->audit->requireListener();
        $board  = $this->board($principal, TaskClientInput::id($body, 'boardId', true) ?? '');
        $parent = null;
        if (isset($body['parentId'])) {
            $parent = $this->task($principal, (string) TaskClientInput::id($body, 'parentId'), TaskApiCapability::EditTasks);
            if ($parent->getBoard()->getId() !== $board->getId()) {
                throw TaskClientApiException::invalidField('parentId');
            }
        }
        if (isset($body['templateId'])) {
            // Templates through the client API come with 3.5.0 (features.templates is false).
            throw TaskClientApiException::invalidField('templateId');
        }
        $data           = new TaskFormData(title: $this->title($body['title'] ?? null));
        $data->columnId = $this->column($board, TaskClientInput::id($body, 'columnId'))->getId();
        $this->fields($data, $body);
        $data->tags = $this->tags(TaskClientInput::strings($body, 'tags', TaskApiTaskOperations::MAX_TAGS));
        $assignees  = $this->assignable($board, $call, TaskClientInput::strings($body, 'assignees', 50));
        $link       = $this->source($call, $body['source'] ?? null);
        $this->audit->requireTaskQuota($board, 1, $principal->user);

        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        $task = $this->transaction->run(function () use ($board, $data, $principal, $parent, $assignees, $link): Task {
            $task = $this->withinPlan(fn (): Task => $this->tasks->create($board, $data, $principal->user, $parent, null, new ColumnTransitionVia($principal->channel, ColumnTransitionVia::OP_CREATE)));
            foreach ($assignees as $user) {
                $this->members->assign($task, new TaskMemberFormData($user, TaskMemberRole::Assignee), $principal->user);
            }
            if ($link instanceof TaskClientSourceLink && $this->links instanceof TaskLinkAttacher) {
                $this->links->attachObject($task, $link->objectType, $link->objectId, $principal->user, $link->label);
            }

            return $task;
        });
        $this->audit->written($principal, 'task.create', $board, $task->getId(), $parent instanceof Task ? ['parent' => $parent->getId()] : []);

        return $task;
    }

    /**
     * `PATCH tasks/{id}`: the fields given, the task's row locked and its revision compared first.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public function update(TaskClientCall $call, string $taskId, array $body, int $ifMatch): Task
    {
        TaskClientInput::only($body, self::PATCH_FIELDS);
        $principal = $call->principal;
        $this->audit->requireListener();
        $task = $this->task($principal, $taskId, TaskApiCapability::EditTasks);
        // Every field is checked before the transaction (a refusal writes nothing) …
        $this->changed($task, $body);
        $archived = array_key_exists('archived', $body) ? TaskClientInput::bool($body, 'archived') : null;
        if ($archived === false && $task->isArchived() && !$task->isCompleted() && $this->tasks->activeTaskRefusal($task->getBoard(), 1) instanceof LimitRefusal) {
            // The host's active-task limit (`sync.limit_checker`).
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        }

        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        return $this->transaction->run(function () use ($principal, $task, $body, $archived, $ifMatch): Task {
            $this->requireRevision($task, $ifMatch);
            // … and applied to the task as locked (audit ZCS-I1: the fields the client did not send are the row's).
            $data    = $this->changed($task, $body);
            $changed = [];
            if ($archived === false && $this->withinPlan(fn (): bool => $this->tasks->restore($task, $principal->user), $task)) {
                $changed[] = 'archived';
            }
            if ($data != TaskFormData::fromTask($task)) {
                $this->withinPlan(fn () => $this->tasks->update($task, $data, $principal->user, new ColumnTransitionVia($principal->channel)), $task);
                $changed[] = 'fields';
            }
            if ($archived === true && $this->tasks->archive($task, $principal->user)) {
                $changed[] = 'archived';
            }
            $this->audit->written($principal, 'task.update', $task->getBoard(), $task->getId(), [], $changed);

            return $task;
        }, [$task]);
    }

    /**
     * `POST tasks/{id}/move`: to a column (between columns: `If-Match` compared; inside its column: `If-Match: *`
     * accepted, last writer wins), before or after another task of that column, else at its end.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public function move(TaskClientCall $call, string $taskId, array $body, ?int $ifMatch): Task
    {
        TaskClientInput::only($body, ['columnId', 'beforeTaskId', 'afterTaskId']);
        $principal = $call->principal;
        $this->audit->requireListener();
        $task   = $this->task($principal, $taskId, TaskApiCapability::EditTasks);
        $column = $this->column($task->getBoard(), TaskClientInput::id($body, 'columnId', true));
        $moves  = $task->getColumn()?->getId() !== $column->getId();
        if ($moves && $ifMatch === null) {
            throw new TaskClientApiException('precondition_required', 428);
        }
        $position = $this->position($call, $task, $column, TaskClientInput::id($body, 'beforeTaskId'), TaskClientInput::id($body, 'afterTaskId'));

        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        return $this->transaction->run(function () use ($principal, $task, $column, $position, $ifMatch, $moves): Task {
            // Columns before tasks (the Task API's order), then the revision on the locked row.
            $this->rowLock?->lockColumns([$column->getId()]);
            if ($ifMatch !== null) {
                $this->requireRevision($task, $ifMatch);
            }
            $this->withinPlan(fn () => $this->tasks->move($task, $column->getId(), $position, $principal->user, new ColumnTransitionVia($principal->channel, ColumnTransitionVia::OP_MOVE)), $task);
            $this->audit->written($principal, 'task.move', $task->getBoard(), $task->getId(), ['column' => $column->getId()], $moves ? ['status'] : ['position']);

            return $task;
        }, [$task]);
    }

    /**
     * `PUT tasks/{id}/members/{userId}` (`$role` set) and `DELETE …` (`$role` null: every role of that person).
     *
     * @throws TaskClientApiException
     */
    public function member(TaskClientCall $call, string $taskId, string $userId, ?TaskMemberRole $role, int $ifMatch): Task
    {
        $principal = $call->principal;
        $this->audit->requireListener();
        $task = $this->task($principal, $taskId, TaskApiCapability::EditTasks);
        $user = $this->person($call, $userId);
        // An unknown person and one the board's assignee rule refuses: one answer.
        if ($role instanceof TaskMemberRole && ($user === null || !$this->members->canBeAssigned($task->getBoard(), $user) || !in_array($user, $this->members->assignableUsers($task->getBoard()) ?? [$user], true))) {
            throw TaskClientApiException::invalidField('userId');
        }
        $assign = $role instanceof TaskMemberRole && $user !== null ? [$user, $role] : null;

        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        return $this->transaction->run(function () use ($principal, $task, $assign, $userId, $ifMatch): Task {
            $this->requireRevision($task, $ifMatch);
            $changed = false;
            if ($assign !== null) {
                // Checked above with the board's assignee rule.
                $this->members->assign($task, new TaskMemberFormData($assign[0], $assign[1]), $principal->user);
                $changed = true;
            } else {
                foreach ($task->getMembers()->toArray() as $member) {
                    if ($this->apiId($member->getUser()) === $userId) {
                        $changed = $this->members->unassign($task, $member->getId(), $principal->user) || $changed;
                    }
                }
            }
            if ($changed) {
                $this->audit->written($principal, 'task.members', $task->getBoard(), $task->getId(), [], [$assign !== null ? 'members.add' : 'members.remove']);
            }

            return $task;
        }, [$task]);
    }

    /**
     * `POST tasks/{id}/checklist-items`: an item (a subtask tagged `checklist:<name>`) through the Task API's checklist
     * operations.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public function createChecklistItem(TaskClientCall $call, string $taskId, array $body): Task
    {
        TaskClientInput::only($body, ['checklist', 'title']);
        $name = trim(TaskClientInput::string($body, 'checklist'));
        if ($name === '' || mb_strlen($name) > 100) {
            throw TaskClientApiException::invalidField('checklist');
        }
        $title = $this->title($body['title'] ?? null);

        return $this->checklists->createItemTask($call->principal, $taskId, $name, $title);
    }

    /**
     * `PATCH tasks/{id}/checklist-items/{itemId}`: `title`, `done` (to the done column, or back to the first one), with the
     * item's own `If-Match`.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public function updateChecklistItem(TaskClientCall $call, string $taskId, string $itemId, array $body, int $ifMatch): Task
    {
        TaskClientInput::only($body, ['title', 'done']);
        $principal = $call->principal;
        $task      = $this->task($principal, $taskId, TaskApiCapability::WorkOnTask);
        $item      = $this->checklists->itemOf($principal, $task, $itemId);
        $input     = [];
        if (isset($body['title'])) {
            $input['name'] = $this->title($body['title']);
        }
        if (array_key_exists('done', $body)) {
            $input['resolved'] = TaskClientInput::bool($body, 'done');
        }

        // Audit ZCS-L3: the column a tick enters (the done column, or back to the first) is locked before the item's row,
        // the fixed order of every other write.
        $enters = match ($input['resolved'] ?? null) {
            true    => $item->isCompleted() ? null : ColumnTypes::doneColumn($item->getBoard()),
            false   => $item->isCompleted() ? $item->getBoard()->getColumns()->first() ?: null : null,
            default => null,
        };
        // @igor-ignore - The closure runs once, now, inside run(); the stateless transaction keeps no reference to it.
        $this->transaction->run(function () use ($principal, $task, $item, $input, $ifMatch, $enters): void {
            if ($enters instanceof BoardColumn) {
                $this->rowLock?->lockColumns([$enters->getId()]);
            }
            $this->requireRevision($item, $ifMatch);
            $this->checklists->updateItemTask($principal, $task, $item, $input);
        }, [$item]);

        return $item;
    }

    /**
     * The task's form data with the body's fields applied (each checked with the forms' rules).
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    private function changed(Task $task, array $body): TaskFormData
    {
        $data = TaskFormData::fromTask($task);
        // The column moves only through move(): update() never moves the task.
        $data->columnId = $task->getColumn()?->getId();
        if (isset($body['title'])) {
            $data->title = $this->title($body['title']);
        }
        if (isset($body['tags'])) {
            $data->tags = $this->tags(TaskClientInput::strings($body, 'tags', TaskApiTaskOperations::MAX_TAGS));
        }
        if (array_key_exists('color', $body)) {
            $color = TaskClientInput::nullableString($body, 'color');
            $this->validate($color, TaskConstraints::taskColor(), 'color');
            $data->color = $color;
        }
        $this->fields($data, $body);

        return $data;
    }

    /**
     * A task within reach for the capability, of exactly this id (REQ-SEC-006, audit ZCS-I2: a folding collation may
     * load a look-alike), else `404`.
     */
    private function task(TaskApiPrincipal $principal, string $taskId, TaskApiCapability $capability): Task
    {
        $task = $this->guard->task($principal, $taskId, $capability);

        return $task->getId() === $taskId ? $task : throw TaskClientApiException::notFound();
    }

    /** A board the person may edit, of exactly this id (audit ZCS-I2), else `404`. */
    private function board(TaskApiPrincipal $principal, string $boardId): TaskBoard
    {
        $board = $this->guard->board($principal, $boardId, TaskApiCapability::EditTasks);

        return $board->getId() === $boardId ? $board : throw TaskClientApiException::notFound();
    }

    /**
     * The revision on the locked row (inside the transaction), else `409 conflict` with the current one.
     *
     * @throws TaskApiRefusal
     */
    private function requireRevision(Task $task, int $ifMatch): void
    {
        $current = $this->transaction->lockedRevision($task);
        if ($current !== $ifMatch) {
            throw TaskApiRefusal::stale($current);
        }
    }

    /**
     * The index a move puts the task at in its target column: before `$before`, after `$after`, else at the end.
     *
     * @throws TaskClientApiException
     */
    private function position(TaskClientCall $call, Task $task, BoardColumn $column, ?string $before, ?string $after): int
    {
        $ids = $this->queries->columnTaskIds($task->getBoard()->getId(), $column->getId(), $task->getParent()?->getId(), $task->getId());
        foreach (['beforeTaskId' => $before, 'afterTaskId' => $after] as $field => $anchor) {
            if ($anchor === null) {
                continue;
            }
            $index = array_search($anchor, $ids, true);
            $other = $index === false ? null : $this->taskRepository->findById($anchor);
            // An anchor the person may not see is not in the column for them: the same answer as an unknown one.
            if (!is_int($index) || !$other instanceof Task || !$this->guard->canSeeTask($call->principal, $other)) {
                throw TaskClientApiException::invalidField($field);
            }

            return $field === 'beforeTaskId' ? $index : $index + 1;
        }

        return count($ids);
    }

    /**
     * The column of the board with this id (exactly), or the first open one when none is given.
     *
     * @throws TaskClientApiException
     */
    private function column(TaskBoard $board, ?string $columnId): BoardColumn
    {
        if ($columnId === null) {
            return ColumnTypes::firstOpen($board) ?? ColumnTypes::ordered($board)[0] ?? throw TaskClientApiException::invalidField('columnId');
        }
        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $columnId) {
                return $column;
            }
        }

        throw TaskClientApiException::invalidField('columnId');
    }

    /**
     * `description`, `priority`, `dueAt`, `startAt`, `allDay`, `estimateMinutes` (the fields create and update share).
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    private function fields(TaskFormData $data, array $body): void
    {
        if (array_key_exists('description', $body)) {
            $description = TaskClientInput::nullableString($body, 'description');
            if ($description !== null && mb_strlen($description) > TaskClientInput::DESCRIPTION_MAX) {
                throw TaskClientApiException::invalidField('description');
            }
            $data->description = $description;
        }
        if (array_key_exists('priority', $body)) {
            $data->priority = TaskPriority::tryFrom(TaskClientInput::string($body, 'priority')) ?? throw TaskClientApiException::invalidField('priority');
        }
        $allDay = array_key_exists('allDay', $body) && TaskClientInput::bool($body, 'allDay');
        foreach (['dueAt' => 'dueAt', 'startAt' => 'startAt'] as $field => $property) {
            if (array_key_exists($field, $body)) {
                $date              = TaskClientInput::date($body, $field);
                $data->{$property} = $date instanceof DateTimeImmutable && $allDay ? $date->setTime(0, 0) : $date;
            } elseif ($allDay && $data->{$property} instanceof DateTimeImmutable) {
                $data->{$property} = $data->{$property}->setTime(0, 0);
            }
        }
        if (array_key_exists('estimateMinutes', $body)) {
            $data->estimatedMinutes = TaskClientInput::nullableInt($body, 'estimateMinutes', 0, 1_000_000);
        }
        if (TaskConstraints::startsAfterDue($data->startAt, $data->dueAt)) {
            throw TaskClientApiException::invalidField('startAt');
        }
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private function tags(array $tags): array
    {
        $tags = array_values(array_unique(array_map(trim(...), $tags)));
        foreach ($tags as $tag) {
            $this->validate($tag, TaskConstraints::tagName(), 'tags');
        }

        return $tags;
    }

    private function title(mixed $title): string
    {
        $title = is_string($title) ? trim($title) : '';
        $this->validate($title, TaskConstraints::taskTitle(), 'title');

        return $title;
    }

    /**
     * @param list<Constraint> $constraints
     */
    private function validate(mixed $value, array $constraints, string $field): void
    {
        if ($this->validator->validate($value, $constraints)->count() > 0) {
            throw TaskClientApiException::invalidField($field);
        }
    }

    /**
     * The people named (`"me"` or public ids), each one the board's assignee rule allows; one unknown or refused id is
     * `400 invalid_field` (the same answer).
     *
     * @param list<string> $ids
     *
     * @return list<object>
     */
    private function assignable(TaskBoard $board, TaskClientCall $call, array $ids): array
    {
        $allowed = $this->members->assignableUsers($board);
        $users   = [];
        foreach ($ids as $id) {
            $user = $this->person($call, $id);
            if ($user === null || ($allowed !== null && !in_array($user, $allowed, true)) || !$this->members->canBeAssigned($board, $user)) {
                throw TaskClientApiException::invalidField('assignees');
            }
            $users[] = $user;
        }

        return $users;
    }

    /** `me` or a public id the host knows exactly; null otherwise. */
    private function person(TaskClientCall $call, string $id): ?object
    {
        if ($id === 'me') {
            return $call->principal->user;
        }
        $user = $this->users->find($id);

        return $user !== null && $this->apiId($user) === $id ? $user : null;
    }

    private function apiId(object $user): ?string
    {
        try {
            return $this->users->apiId($user);
        } catch (TaskApiRefusal) {
            return null;
        }
    }

    /**
     * The host object the `source` links to, asked before anything is written (no I/O under a lock).
     *
     * @throws TaskClientApiException
     */
    private function source(TaskClientCall $call, mixed $source): ?TaskClientSourceLink
    {
        if ($source === null) {
            return null;
        }
        $parsed = TaskClientInput::source($source);
        if (!$this->sources instanceof TaskClientSourceLinkInterface || !$this->links instanceof TaskLinkAttacher) {
            throw TaskClientApiException::invalidField('source');
        }
        $link = $this->sources->link($call->client, $parsed);
        if (!$link instanceof TaskClientSourceLink || trim($link->objectType) === '' || trim($link->objectId) === '') {
            throw new TaskClientApiException('source_not_visible', 422);
        }

        return $link;
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @throws TaskApiRefusal
     *
     * @return T
     */
    private function withinPlan(callable $write, ?Task $task = null): mixed
    {
        try {
            return $write();
        } catch (TaskReopenRefusedException) {
            // The host's reopen limit, as the Task API.
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        } catch (ColumnTransitionRefusedException $refused) {
            throw TaskApiRefusal::transitionRefused($refused);
        } catch (TaskTreeRefusedException $refused) {
            throw $task instanceof Task ? TaskApiRefusal::taskWriteRefused($refused, $task) : TaskApiRefusal::treeRefused($refused);
        }
    }
}
