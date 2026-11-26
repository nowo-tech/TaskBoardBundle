<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_filter;
use function array_values;
use function base64_decode;
use function count;
use function explode;
use function in_array;
use function is_string;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;
use function trim;

/**
 * Since 2.2.0 (spec 213, owner Q-O4): ClickUp's checklists, as the import keeps them: each item is a subtask tagged
 * `checklist:<name>`, and a checklist is its task plus that name. A *My tasks* token manages the checklists of its own tasks,
 * items included, though it creates no other task. A checklist with no item does not persist. Deleting an item or a checklist
 * archives the subtasks: nothing tracked on them is lost.
 */
final readonly class TaskApiChecklistOperations
{
    public const TAG_PREFIX = 'checklist:';

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskApiUsersInterface $users,
        private TaskManager $tasks,
        private TaskMemberAssigner $members,
        private TaskApiQueryRepository $queries,
        private ValidatorInterface $validator,
        // Since 2.16.0 (audit PC-L2): a rename retags every item, all or none, now that a tag change can be refused.
        private ?TaskApiTransaction $transaction = null,
    ) {
    }

    /**
     * `POST /v2/task/{id}/checklist`: nothing is stored until it has an item. Returns the task and the name.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: string}
     */
    public function createChecklist(TaskApiPrincipal $principal, string $taskId, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::WorkOnTask);

        return [$task, $this->name($input->string('name', true))];
    }

    /**
     * `POST /v2/checklist/{id}/checklist_item`: a subtask of the task, counted against the host's limit like any task.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: string}
     */
    public function createItem(TaskApiPrincipal $principal, string $checklistId, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        [$task, $name] = $this->checklist($principal, $checklistId);
        $title         = trim((string) $input->string('name', true));
        $this->validate($title, 'name', TaskConstraints::taskTitle());
        $assignee = $this->assignee($principal, $task, $input);
        $this->addItem($principal, $task, $name, $title, $assignee);

        return [$task, $name];
    }

    /**
     * Since 3.4.0 (client API v1, `POST tasks/{id}/checklist-items`): an item of the task's checklist `$name` (a name of
     * at most 100 characters; a new name starts a checklist), the item returned. The same checks as
     * {@see self::createItem()}: the task's reach and the person's right on it, the title's form rule, the quota, the
     * audit.
     *
     * @throws TaskApiRefusal
     */
    public function createItemTask(TaskApiPrincipal $principal, string $taskId, string $name, string $title): Task
    {
        $this->audit->requireListener();
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::WorkOnTask);
        $this->validate($title, 'name', TaskConstraints::taskTitle());

        return $this->addItem($principal, $task, $name, $title, null);
    }

    /**
     * Since 3.4.0 (client API v1): an item of one of the task's checklists (a subtask tagged `checklist:<name>`), else
     * 404 `ACCESS_001`.
     *
     * @throws TaskApiRefusal
     */
    public function itemOf(TaskApiPrincipal $principal, Task $task, string $itemId): Task
    {
        foreach ($this->queries->subtasksOf([$task->getId()]) as $item) {
            if ($item->getId() === $itemId && self::checklistNameOf($item) !== null) {
                return $item;
            }
        }

        throw TaskApiRefusal::notFound();
    }

    /**
     * Since 3.4.0 (client API v1): `name` and `resolved` of an item already found with {@see self::itemOf()}, as
     * {@see self::updateItem()} changes them (the caller holds the transaction and compared the item's revision).
     *
     * @param array{name?: string, resolved?: bool|null} $input
     *
     * @throws TaskApiRefusal
     */
    public function updateItemTask(TaskApiPrincipal $principal, Task $task, Task $item, array $input): void
    {
        $this->audit->requireListener();
        $data = TaskFormData::fromTask($item);
        if (isset($input['name'])) {
            $data->title = trim($input['name']);
            $this->validate($data->title, 'name', TaskConstraints::taskTitle());
        }
        $this->resolve($principal, $item, $input['resolved'] ?? null);
        $data->columnId = $item->getColumn()?->getId();
        if ($data->title !== $item->getTitle()) {
            $this->withinPlan(fn () => $this->tasks->update($item, $data, $principal->user, TaskApiTaskOperations::via($principal, ColumnTransitionVia::OP_CHECKLIST)), $item);
        }
        $this->audit->written($principal, 'checklist_item.update', $task->getBoard(), $task->getId(), ['item' => $item->getId()]);
    }

    /** Since 3.4.0: the checklist name of an item (its `checklist:<name>` tag), else null. */
    public static function checklistNameOf(Task $item): ?string
    {
        foreach ($item->getTags() as $tag) {
            if (str_starts_with($tag, self::TAG_PREFIX) && $tag !== self::TAG_PREFIX) {
                return substr($tag, strlen(self::TAG_PREFIX));
            }
        }

        return null;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function addItem(TaskApiPrincipal $principal, Task $task, string $name, string $title, ?object $assignee): Task
    {
        $this->audit->requireTaskQuota($task->getBoard(), 1, $principal->user);

        $item = $this->withinPlan(fn (): Task => $this->tasks->create($task->getBoard(), new TaskFormData(title: $title, tags: [self::TAG_PREFIX . $name]), $principal->user, $task, null, TaskApiTaskOperations::via($principal, ColumnTransitionVia::OP_CHECKLIST)));
        if ($assignee !== null) {
            $this->members->assign($item, new TaskMemberFormData($assignee, TaskMemberRole::Assignee), $principal->user);
        }
        $this->audit->written($principal, 'checklist_item.create', $task->getBoard(), $task->getId(), ['item' => $item->getId()]);

        return $item;
    }

    /**
     * `resolved`: true moves the item to the done column, false back to the first column.
     *
     * @throws TaskApiRefusal
     */
    private function resolve(TaskApiPrincipal $principal, Task $item, ?bool $resolved): void
    {
        if ($resolved === true && !$item->isCompleted()) {
            $this->withinPlan(fn (): bool => $this->tasks->moveToDone($item, $principal->user, TaskApiTaskOperations::via($principal, ColumnTransitionVia::OP_CHECKLIST)), $item);
        } elseif ($resolved === false && $item->isCompleted()) {
            $first = $item->getBoard()->getColumns()->first();
            if ($first instanceof BoardColumn) {
                $this->withinPlan(fn (): bool => $this->tasks->moveToColumn($item, $first->getId(), $principal->user, TaskApiTaskOperations::via($principal, ColumnTransitionVia::OP_CHECKLIST)), $item);
            }
        }
    }

    /**
     * `PUT /v2/checklist/{id}`: renames it (its items' tag). `position` is not supported.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: string}
     */
    public function updateChecklist(TaskApiPrincipal $principal, string $checklistId, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        [$task, $name] = $this->checklist($principal, $checklistId);
        if (!$input->has('name')) {
            return [$task, $name];
        }
        $renamed = $this->name($input->string('name', true));
        $items   = $this->items($task, $name);
        $rename  = function () use ($principal, $items, $name, $renamed): void {
            foreach ($items as $item) {
                $data       = TaskFormData::fromTask($item);
                $data->tags = [...array_values(array_filter($item->getTags(), static fn (string $tag): bool => $tag !== self::TAG_PREFIX . $name)), self::TAG_PREFIX . $renamed];
                $this->withinPlan(fn () => $this->tasks->update($item, $data, $principal->user, TaskApiTaskOperations::via($principal, ColumnTransitionVia::OP_CHECKLIST)), $item);
            }
        };
        // @igor-ignore - The closure runs once, now; the stateless transaction keeps no reference to it.
        $this->transaction instanceof TaskApiTransaction ? $this->transaction->run($rename, $items) : $rename();
        $this->audit->written($principal, 'checklist.update', $task->getBoard(), $task->getId(), [], ['name']);

        return [$task, $renamed];
    }

    /**
     * `DELETE /v2/checklist/{id}`: its items are archived.
     *
     * @throws TaskApiRefusal
     */
    public function deleteChecklist(TaskApiPrincipal $principal, string $checklistId): Task
    {
        $this->audit->requireListener();
        [$task, $name] = $this->checklist($principal, $checklistId);
        foreach ($this->items($task, $name) as $item) {
            $this->tasks->archive($item, $principal->user);
        }
        $this->audit->written($principal, 'checklist.delete', $task->getBoard(), $task->getId());

        return $task;
    }

    /**
     * `PUT /v2/checklist/{id}/checklist_item/{item}`: `name`, `resolved` (done or back to the first column), `assignee`.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: string}
     */
    public function updateItem(TaskApiPrincipal $principal, string $checklistId, string $itemId, TaskApiInput $input): array
    {
        $this->audit->requireListener();
        [$task, $name] = $this->checklist($principal, $checklistId);
        $item          = $this->item($task, $name, $itemId);
        $data          = TaskFormData::fromTask($item);
        if ($input->has('name')) {
            $data->title = trim((string) $input->string('name', true));
            $this->validate($data->title, 'name', TaskConstraints::taskTitle());
        }
        $resolved = $input->bool('resolved');
        $assignee = $input->has('assignee') ? $this->assignee($principal, $task, $input) : null;

        $this->resolve($principal, $item, $resolved);
        $data->columnId = $item->getColumn()?->getId();
        if ($data->title !== $item->getTitle()) {
            $this->withinPlan(fn () => $this->tasks->update($item, $data, $principal->user, TaskApiTaskOperations::via($principal, ColumnTransitionVia::OP_CHECKLIST)), $item);
        }
        if ($assignee !== null) {
            $this->members->assign($item, new TaskMemberFormData($assignee, TaskMemberRole::Assignee), $principal->user);
        }
        $this->audit->written($principal, 'checklist_item.update', $task->getBoard(), $task->getId(), ['item' => $item->getId()]);

        return [$task, $name];
    }

    /**
     * `DELETE /v2/checklist/{id}/checklist_item/{item}`: archived.
     *
     * @throws TaskApiRefusal
     */
    public function deleteItem(TaskApiPrincipal $principal, string $checklistId, string $itemId): Task
    {
        $this->audit->requireListener();
        [$task, $name] = $this->checklist($principal, $checklistId);
        $item          = $this->item($task, $name, $itemId);
        $this->tasks->archive($item, $principal->user);
        $this->audit->written($principal, 'checklist_item.delete', $task->getBoard(), $task->getId(), ['item' => $item->getId()]);

        return $task;
    }

    /**
     * The task and the name a checklist id stands for, through the guard: the task's checklists are worked on like the task.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: string}
     */
    private function checklist(TaskApiPrincipal $principal, string $checklistId): array
    {
        if (!str_starts_with($checklistId, 'cl_')) {
            throw TaskApiRefusal::notFound();
        }
        $encoded = strtr(substr($checklistId, 3), '-_', '+/');
        $decoded = base64_decode(str_pad($encoded, strlen($encoded) + (4 - strlen($encoded) % 4) % 4, '='), true);
        $parts   = is_string($decoded) ? explode('|', $decoded, 2) : [];
        if (count($parts) !== 2 || $parts[1] === '') {
            throw TaskApiRefusal::notFound();
        }

        return [$this->guard->task($principal, $parts[0], TaskApiCapability::WorkOnTask), $parts[1]];
    }

    /**
     * @return list<Task>
     */
    private function items(Task $task, string $name): array
    {
        return array_values(array_filter($this->queries->subtasksOf([$task->getId()]), static fn (Task $item): bool => in_array(self::TAG_PREFIX . $name, $item->getTags(), true)));
    }

    /**
     * @throws TaskApiRefusal
     */
    private function item(Task $task, string $name, string $itemId): Task
    {
        foreach ($this->items($task, $name) as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        throw TaskApiRefusal::notFound();
    }

    /**
     * A *My tasks* token assigns an item to its own person only.
     *
     * @throws TaskApiRefusal
     */
    private function assignee(TaskApiPrincipal $principal, Task $task, TaskApiInput $input): ?object
    {
        $apiId = $input->string('assignee');
        if ($apiId === null) {
            return null;
        }
        $user    = $this->users->find($apiId);
        $allowed = $this->members->assignableUsers($task->getBoard());
        if ($user === null || ($allowed !== null && !in_array($user, $allowed, true))) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid assignee');
        }
        if ($principal->level === TaskApiLevel::MyTasks && $apiId !== $this->users->apiId($principal->user)) {
            throw TaskApiRefusal::levelRefused();
        }

        return $user;
    }

    /**
     * @throws TaskApiRefusal
     */
    private function name(?string $name): string
    {
        $name = trim((string) $name);
        // The name lives in a tag, after its prefix.
        $this->validate($name, 'name', TaskConstraints::tagName());

        return $name;
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @throws TaskApiRefusal
     */
    private function validate(string $value, string $field, array $constraints): void
    {
        if ($this->validator->validate($value, $constraints)->count() > 0) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . $field);
        }
    }

    /**
     * Since 2.2.1 (audit IB-L1): every write here that the host's limit refuses is 403 `PLAN_002`, never a 500.
     *
     * @template T
     *
     * @param callable(): T $write
     * @param Task|null $task since 2.21.0: the existing task the write changes; a `conflict` on it is 409 `TASK_409`
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
            throw new TaskApiRefusal(403, 'PLAN_002', 'The limit of active tasks is reached');
        } catch (ColumnTransitionRefusedException $refused) {
            // Since 2.16.0 (spec 234).
            throw TaskApiRefusal::transitionRefused($refused);
        } catch (TaskTreeRefusedException $refused) {
            // Since 2.20.0: a subtask past `max_subtask_depth` (PARENT_005). Since 2.21.0 (audit SF-2): a write on an existing
            // task that changed meanwhile is TASK_409 with its current revision.
            throw $task instanceof Task ? TaskApiRefusal::taskWriteRefused($refused, $task) : TaskApiRefusal::treeRefused($refused);
        }
    }
}
