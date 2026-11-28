<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiChecklistOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTimeOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiActors;
use Nowo\TaskBoardBundle\Api\Security\TaskApiIdempotencyListener;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\Serializer\TaskApiReadSerializer;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiRevision;
use Nowo\TaskBoardBundle\Api\Value\TaskApiAttachment;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Provenance\Attribute\AsWriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;
use function is_array;
use function is_string;
use function pathinfo;

use const PATHINFO_EXTENSION;

/**
 * Since 2.2.0 (spec 213): the write endpoints. Thin: parse the body, call one operation, answer as ClickUp does, with
 * `warnings` for the fields nothing read.
 */
#[AsWriteChannel(WriteChannel::Api)]
#[Route(requirements: TaskBoardRouteLoader::API_REQUIREMENTS, defaults: TaskBoardRouteLoader::API_DEFAULTS)]
final readonly class TaskApiWriteController
{
    /** Since 2.22.0: request attribute, the attachment the host stored in an earlier attempt of this request. */
    public const STORED_ATTACHMENT = '_nowo_task_board_api_stored_attachment';

    public function __construct(
        private TaskApiTaskOperations $tasks,
        private TaskApiChecklistOperations $checklists,
        private TaskApiReadOperations $reads,
        private TaskApiReadSerializer $serializer,
        private TaskApiTimeOperations $time,
        private int $maxBodyBytes,
        private ClockInterface $clock = new NativeClock(),
        // Since 2.22.0 (Beacon spec 241 T020): write-unit replay safety; null in a hand-built controller.
        private ?TaskApiActors $actors = null,
    ) {
    }

    #[Route('/v2/list/{listId}/task', name: 'nowo_task_board_api_task_create', methods: ['POST'])]
    public function createTask(Request $request, string $listId): JsonResponse
    {
        $input = $this->input($request, TaskApiTaskOperations::NOT_SUPPORTED);
        $task  = $this->tasks->createTask($this->principal($request), $listId, $input);

        return $this->taskAnswer($request, $task, $input);
    }

    #[Route('/v2/task/{taskId}', name: 'nowo_task_board_api_task_update', methods: ['PUT'])]
    public function updateTask(Request $request, string $taskId): JsonResponse
    {
        $input = $this->input($request, TaskApiTaskOperations::NOT_SUPPORTED);
        // Since 2.16.0 (spec 234, FR-018): `If-Match`, else `base_revision` in the body.
        $task = $this->tasks->updateTask($this->principal($request), $taskId, $input, TaskApiRevision::ifMatch($request->headers->get('If-Match')));

        return $this->taskAnswer($request, $task, $input);
    }

    /**
     * Since 2.16.0 (spec 234, FR-021): `POST /v2/task/bulk`, all or none; answers `{"tasks": [...]}` in the request's order.
     */
    #[Route('/v2/task/bulk', name: 'nowo_task_board_api_task_bulk', methods: ['POST'])]
    public function updateTasks(Request $request): JsonResponse
    {
        $input     = $this->input($request);
        $principal = $this->principal($request);
        $tasks     = $this->tasks->updateMany($principal, $input);
        TaskApiIdempotencyListener::target($request, $tasks[0]->getBoard());
        $page = $this->reads->taskList($principal, array_map(static fn (Task $task): string => $task->getId(), $tasks));
        $data = [];
        foreach ($page->tasks as $task) {
            $data[] = $this->serializer->task($task, $page);
        }

        return $this->answer(['tasks' => $data], $input);
    }

    #[Route('/v2/task/{taskId}', name: 'nowo_task_board_api_task_delete', methods: ['DELETE'])]
    public function deleteTask(Request $request, string $taskId): JsonResponse
    {
        // A body is read for its warnings only: nothing in it is saved.
        $input = $this->input($request);
        TaskApiIdempotencyListener::target($request, $this->tasks->deleteTask($this->principal($request), $taskId));

        return $this->answer([], $input);
    }

    #[Route('/v2/task/{taskId}/comment', name: 'nowo_task_board_api_comment_create', methods: ['POST'])]
    public function addComment(Request $request, string $taskId): JsonResponse
    {
        $input = $this->input($request, ['notify_all', 'assignee', 'group_assignee']);
        // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
        $comment = $this->tasks->addComment($this->principal($request), $taskId, $input);
        TaskApiIdempotencyListener::target($request, $comment->getTask()->getBoard());

        return $this->answer(['id' => $comment->getId(), 'hist_id' => $comment->getId(), 'date' => (int) $comment->getCreatedAt()->format('Uv')], $input);
    }

    /**
     * Since 2.16.0 (spec 234): `PUT /v2/comment/{id}`; answers `{}` as ClickUp does.
     */
    #[Route('/v2/comment/{commentId}', name: 'nowo_task_board_api_comment_update', methods: ['PUT'])]
    public function updateComment(Request $request, string $commentId): JsonResponse
    {
        $input   = $this->input($request, ['assignee', 'group_assignee', 'resolved', 'notify_all']);
        $comment = $this->tasks->editComment($this->principal($request), $commentId, $input);
        TaskApiIdempotencyListener::target($request, $comment->getTask()->getBoard());

        return $this->answer([], $input);
    }

    #[Route('/v2/task/{taskId}/attachment', name: 'nowo_task_board_api_attachment_create', methods: ['POST'])]
    public function addAttachment(Request $request, string $taskId): JsonResponse
    {
        // The form fields besides the file are read for their warnings only.
        /** @var array<string, mixed> $fields */
        $fields = $request->request->all();
        $input  = new TaskApiInput($fields, ['filename', 'custom_task_ids', 'team_id']);
        $file   = $request->files->get('attachment');
        $upload = $file instanceof UploadedFile ? $file : null;
        // Since 2.22.0 (Beacon spec 241 T020): a replayed attempt of a write unit (the same request) gets the file the host
        // already stored, never a second copy; the access checks run again.
        $kept   = $request->attributes->get(self::STORED_ATTACHMENT);
        $key    = $upload instanceof UploadedFile && $upload->isValid() ? $taskId . '|' . hash_file('sha256', $upload->getPathname()) : null;
        $stored = is_array($kept) && $key !== null && ($kept[0] ?? null) === $key && ($kept[1] ?? null) instanceof TaskApiAttachment ? $kept[1] : null;
        // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
        [$task, $attachment, $committed] = $this->tasks->addAttachment($this->principal($request), $taskId, $upload, $stored);
        // Audit SU2-I1: kept for a replay only when the host's store committed its work; otherwise a replay stores again.
        $request->attributes->set(self::STORED_ATTACHMENT, $committed ? [$key, $attachment] : null);
        $extension = pathinfo($attachment->title, PATHINFO_EXTENSION);
        TaskApiIdempotencyListener::target($request, $task->getBoard());

        return $this->answer([
            'id'              => $attachment->id,
            'version'         => '0',
            'date'            => (int) $this->clock->now()->format('Uv'),
            'title'           => $attachment->title,
            'extension'       => $extension,
            'thumbnail_small' => null,
            'thumbnail_large' => null,
            'url'             => $attachment->url,
        ], $input);
    }

    #[Route('/v2/team/{teamId}/time_entries', name: 'nowo_task_board_api_time_log', methods: ['POST'])]
    public function logTime(Request $request, string $teamId): JsonResponse
    {
        $input          = $this->input($request, TaskApiTimeOperations::NOT_SUPPORTED);
        [$task, $entry] = $this->time->logTime($this->principal($request), $teamId, $input);
        TaskApiIdempotencyListener::target($request, $task?->getBoard());

        return $this->answer(['data' => $this->serializer->timeEntry($entry, $teamId)], $input);
    }

    /** Since 2.12.0: the `billable` flag of one of the token's own time entries. */
    #[Route('/v2/team/{teamId}/time_entries/{timerId}', name: 'nowo_task_board_api_time_update', methods: ['PUT'])]
    public function updateTime(Request $request, string $teamId, string $timerId): JsonResponse
    {
        $input           = $this->input($request, TaskApiTimeOperations::UPDATE_NOT_SUPPORTED);
        [$board, $entry] = $this->time->updateTime($this->principal($request), $teamId, $timerId, $input);
        TaskApiIdempotencyListener::target($request, $board);

        return $this->answer(['data' => $this->serializer->timeEntry($entry, $teamId)], $input);
    }

    /** Tracked time is never deleted through the API (owner, Q-O7): refused before anything is read or looked up. */
    #[Route('/v2/team/{teamId}/time_entries/{timerId}', name: 'nowo_task_board_api_time_delete', methods: ['DELETE'])]
    public function deleteTime(): JsonResponse
    {
        throw new TaskApiRefusal(403, 'TIME_002', 'Time entries are not deleted through the API');
    }

    #[Route('/v2/task/{taskId}/tag/{tagName}', name: 'nowo_task_board_api_tag_add', methods: ['POST'])]
    public function addTag(Request $request, string $taskId, string $tagName): JsonResponse
    {
        $input = $this->input($request);

        return $this->empty($request, $this->tasks->tag($this->principal($request), $taskId, $tagName, true), $input);
    }

    #[Route('/v2/task/{taskId}/tag/{tagName}', name: 'nowo_task_board_api_tag_remove', methods: ['DELETE'])]
    public function removeTag(Request $request, string $taskId, string $tagName): JsonResponse
    {
        $input = $this->input($request);

        return $this->empty($request, $this->tasks->tag($this->principal($request), $taskId, $tagName, false), $input);
    }

    /** Since 2.19.0 (FR-028): `{"value": …}` on a native field; a provider's field stays 409 `FIELD_001`. */
    #[Route('/v2/task/{taskId}/field/{fieldId}', name: 'nowo_task_board_api_field_set', methods: ['POST'])]
    public function setField(Request $request, string $taskId, string $fieldId = ''): JsonResponse
    {
        $input = $this->input($request);

        // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
        return $this->empty($request, $this->tasks->setField($this->principal($request), $taskId, $fieldId, $input), $input);
    }

    #[Route('/v2/task/{taskId}/dependency', name: 'nowo_task_board_api_dependency_add', methods: ['POST'])]
    public function addDependency(Request $request, string $taskId): JsonResponse
    {
        $input          = $this->input($request);
        [$other, $type] = $this->dependencyOf($input->string('depends_on'), $input->string('dependency_of'));

        return $this->empty($request, $this->tasks->relate($this->principal($request), $taskId, $other, $type), $input);
    }

    #[Route('/v2/task/{taskId}/dependency', name: 'nowo_task_board_api_dependency_remove', methods: ['DELETE'])]
    public function removeDependency(Request $request, string $taskId): JsonResponse
    {
        $query          = new TaskApiInput($request->query->all());
        [$other, $type] = $this->dependencyOf($query->string('depends_on'), $query->string('dependency_of'));

        $input = $this->input($request);

        return $this->empty($request, $this->tasks->unrelate($this->principal($request), $taskId, $other, [TaskDependencyType::Blocks, TaskDependencyType::BlockedBy], $type === TaskDependencyType::BlockedBy), $input);
    }

    #[Route('/v2/task/{taskId}/link/{linksTo}', name: 'nowo_task_board_api_link_add', methods: ['POST'])]
    public function addLink(Request $request, string $taskId, string $linksTo): JsonResponse
    {
        $task = $this->tasks->relate($this->principal($request), $taskId, $linksTo, TaskDependencyType::Related);

        return $this->taskAnswer($request, $task, $this->input($request), true);
    }

    #[Route('/v2/task/{taskId}/link/{linksTo}', name: 'nowo_task_board_api_link_remove', methods: ['DELETE'])]
    public function removeLink(Request $request, string $taskId, string $linksTo): JsonResponse
    {
        $task = $this->tasks->unrelate($this->principal($request), $taskId, $linksTo, [TaskDependencyType::Related, TaskDependencyType::Duplicates], null);

        return $this->taskAnswer($request, $task, $this->input($request), true);
    }

    #[Route('/v2/task/{taskId}/checklist', name: 'nowo_task_board_api_checklist_create', methods: ['POST'])]
    public function createChecklist(Request $request, string $taskId): JsonResponse
    {
        $input         = $this->input($request);
        [$task, $name] = $this->checklists->createChecklist($this->principal($request), $taskId, $input);

        return $this->checklistAnswer($request, $task, $name, $input);
    }

    #[Route('/v2/checklist/{checklistId}', name: 'nowo_task_board_api_checklist_update', methods: ['PUT'])]
    public function updateChecklist(Request $request, string $checklistId): JsonResponse
    {
        $input  = $this->input($request, ['position']);
        [$task] = $this->checklists->updateChecklist($this->principal($request), $checklistId, $input);

        return $this->empty($request, $task, $input);
    }

    #[Route('/v2/checklist/{checklistId}', name: 'nowo_task_board_api_checklist_delete', methods: ['DELETE'])]
    public function deleteChecklist(Request $request, string $checklistId): JsonResponse
    {
        $input = $this->input($request);

        return $this->empty($request, $this->checklists->deleteChecklist($this->principal($request), $checklistId), $input);
    }

    #[Route('/v2/checklist/{checklistId}/checklist_item', name: 'nowo_task_board_api_item_create', methods: ['POST'])]
    public function createItem(Request $request, string $checklistId): JsonResponse
    {
        $input         = $this->input($request);
        [$task, $name] = $this->checklists->createItem($this->principal($request), $checklistId, $input);

        return $this->checklistAnswer($request, $task, $name, $input);
    }

    #[Route('/v2/checklist/{checklistId}/checklist_item/{itemId}', name: 'nowo_task_board_api_item_update', methods: ['PUT'])]
    public function updateItem(Request $request, string $checklistId, string $itemId): JsonResponse
    {
        $input         = $this->input($request, ['parent']);
        [$task, $name] = $this->checklists->updateItem($this->principal($request), $checklistId, $itemId, $input);

        return $this->checklistAnswer($request, $task, $name, $input);
    }

    #[Route('/v2/checklist/{checklistId}/checklist_item/{itemId}', name: 'nowo_task_board_api_item_delete', methods: ['DELETE'])]
    public function deleteItem(Request $request, string $checklistId, string $itemId): JsonResponse
    {
        $input = $this->input($request);

        return $this->empty($request, $this->checklists->deleteItem($this->principal($request), $checklistId, $itemId), $input);
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

    /**
     * @param bool $wrapped ClickUp's link endpoints answer `{"task": …}`
     */
    private function taskAnswer(Request $request, Task $task, TaskApiInput $input, bool $wrapped = false): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $task->getBoard());
        $page = $this->reads->task($this->principal($request), $task->getId());
        $data = $this->serializer->task($page->tasks[0], $page);

        // Since 2.16.0 (spec 234): the revision as an ETag.
        return TaskApiRevision::etag($this->answer($wrapped ? ['task' => $data] : $data, $input), $page->tasks[0]);
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
        // Since 2.22.0 (Beacon spec 241 T020): the user as the entity manager manages it in this attempt of a write unit.
        return $this->actors?->principal($request) ?? TaskApiRequestListener::principalOf($request);
    }
}
