<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiChecklistOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiIdempotencyListener;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Since 2.2.0 (spec 213): the write endpoints. Thin: parse the body, call one operation, answer as ClickUp does, with
 * `warnings` for the fields nothing read.
 */
final readonly class TaskApiWriteController
{
    public function __construct(
        private TaskApiTaskOperations $tasks,
        private TaskApiChecklistOperations $checklists,
        private TaskApiReadOperations $reads,
        private TaskApiReadSerializer $serializer,
        private int $maxBodyBytes,
    ) {
    }

    public function createTask(Request $request, string $listId): JsonResponse
    {
        $input = $this->input($request, TaskApiTaskOperations::NOT_SUPPORTED);
        $task  = $this->tasks->createTask($this->principal($request), $listId, $input);

        return $this->taskAnswer($request, $task, $input);
    }

    public function updateTask(Request $request, string $taskId): JsonResponse
    {
        $input = $this->input($request, TaskApiTaskOperations::NOT_SUPPORTED);
        $task  = $this->tasks->updateTask($this->principal($request), $taskId, $input);

        return $this->taskAnswer($request, $task, $input);
    }

    public function deleteTask(Request $request, string $taskId): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $this->tasks->deleteTask($this->principal($request), $taskId));

        return new JsonResponse((object) []);
    }

    public function addComment(Request $request, string $taskId): JsonResponse
    {
        $input   = $this->input($request, ['notify_all', 'assignee', 'group_assignee']);
        $comment = $this->tasks->addComment($this->principal($request), $taskId, $input);
        TaskApiIdempotencyListener::target($request, $comment->getTask()->getBoard());

        return $this->answer(['id' => $comment->getId(), 'hist_id' => $comment->getId(), 'date' => $comment->getCreatedAt()->format('Uv')], $input);
    }

    public function addTag(Request $request, string $taskId, string $tagName): JsonResponse
    {
        return $this->empty($request, $this->tasks->tag($this->principal($request), $taskId, $tagName, true));
    }

    public function removeTag(Request $request, string $taskId, string $tagName): JsonResponse
    {
        return $this->empty($request, $this->tasks->tag($this->principal($request), $taskId, $tagName, false));
    }

    public function setField(Request $request, string $taskId): JsonResponse
    {
        $this->tasks->setField($this->principal($request), $taskId);
    }

    public function addDependency(Request $request, string $taskId): JsonResponse
    {
        $input          = $this->input($request);
        [$other, $type] = $this->dependencyOf($input->string('depends_on'), $input->string('dependency_of'));

        return $this->empty($request, $this->tasks->relate($this->principal($request), $taskId, $other, $type), $input);
    }

    public function removeDependency(Request $request, string $taskId): JsonResponse
    {
        $query          = new TaskApiInput($request->query->all());
        [$other, $type] = $this->dependencyOf($query->string('depends_on'), $query->string('dependency_of'));

        return $this->empty($request, $this->tasks->unrelate($this->principal($request), $taskId, $other, [TaskDependencyType::Blocks, TaskDependencyType::BlockedBy], $type === TaskDependencyType::BlockedBy));
    }

    public function addLink(Request $request, string $taskId, string $linksTo): JsonResponse
    {
        $task = $this->tasks->relate($this->principal($request), $taskId, $linksTo, TaskDependencyType::Related);

        return $this->taskAnswer($request, $task, $this->input($request));
    }

    public function removeLink(Request $request, string $taskId, string $linksTo): JsonResponse
    {
        $task = $this->tasks->unrelate($this->principal($request), $taskId, $linksTo, [TaskDependencyType::Related, TaskDependencyType::Duplicates], null);

        return $this->taskAnswer($request, $task, $this->input($request));
    }

    public function createChecklist(Request $request, string $taskId): JsonResponse
    {
        $input         = $this->input($request);
        [$task, $name] = $this->checklists->createChecklist($this->principal($request), $taskId, $input);

        return $this->checklistAnswer($request, $task, $name, $input);
    }

    public function updateChecklist(Request $request, string $checklistId): JsonResponse
    {
        $input  = $this->input($request, ['position']);
        [$task] = $this->checklists->updateChecklist($this->principal($request), $checklistId, $input);

        return $this->empty($request, $task, $input);
    }

    public function deleteChecklist(Request $request, string $checklistId): JsonResponse
    {
        return $this->empty($request, $this->checklists->deleteChecklist($this->principal($request), $checklistId));
    }

    public function createItem(Request $request, string $checklistId): JsonResponse
    {
        $input         = $this->input($request);
        [$task, $name] = $this->checklists->createItem($this->principal($request), $checklistId, $input);

        return $this->checklistAnswer($request, $task, $name, $input);
    }

    public function updateItem(Request $request, string $checklistId, string $itemId): JsonResponse
    {
        $input         = $this->input($request, ['parent']);
        [$task, $name] = $this->checklists->updateItem($this->principal($request), $checklistId, $itemId, $input);

        return $this->checklistAnswer($request, $task, $name, $input);
    }

    public function deleteItem(Request $request, string $checklistId, string $itemId): JsonResponse
    {
        return $this->empty($request, $this->checklists->deleteItem($this->principal($request), $checklistId, $itemId));
    }

    /**
     * @throws TaskApiRefusal
     *
     * @return array{0: string, 1: TaskDependencyType}
     */
    private function dependencyOf(?string $dependsOn, ?string $dependencyOf): array
    {
        return match (true) {
            is_string($dependsOn) && $dependencyOf === null => [$dependsOn, TaskDependencyType::BlockedBy],
            is_string($dependencyOf) && $dependsOn === null => [$dependencyOf, TaskDependencyType::Blocks],
            default                                         => throw new TaskApiRefusal(400, 'INPUT_002', 'Give one of depends_on and dependency_of'),
        };
    }

    private function taskAnswer(Request $request, Task $task, TaskApiInput $input): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $task->getBoard());
        $page = $this->reads->task($this->principal($request), $task->getId());

        return $this->answer($this->serializer->task($page->tasks[0], $page), $input);
    }

    private function checklistAnswer(Request $request, Task $task, string $name, TaskApiInput $input): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $task->getBoard());
        $page = $this->reads->task($this->principal($request), $task->getId());

        return $this->answer(['checklist' => $this->serializer->checklist($page->tasks[0], $page, $name)], $input);
    }

    private function empty(Request $request, Task $task, ?TaskApiInput $input = null): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $task->getBoard());

        return $this->answer([], $input);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function answer(array $data, ?TaskApiInput $input): JsonResponse
    {
        $warnings = $input?->warnings() ?? [];
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
