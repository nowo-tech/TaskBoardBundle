<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;

/**
 * Attaches external links (MR, PR, URL) to tasks.
 */
final readonly class TaskLinkAttacher
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private GitLabMergeRequestLinkParser $gitlabMrParser,
        private TaskChangeRecorder $changeRecorder,
    ) {
    }

    public function attach(Task $task, TaskLinkFormData $data, object $user): TaskLink
    {
        [$linkType, $label, $externalId] = $this->resolveLinkMeta($data);

        $link = new TaskLink(
            task: $task,
            linkType: $linkType,
            url: $data->url,
            label: $label,
            externalId: $externalId,
        );

        $task->addLink($link);
        $this->changeRecorder->record(
            $task,
            $user,
            TaskChangeType::LinkAdded,
            null,
            $this->linkSummary($link),
            $link->getUrl(),
        );
        $this->taskRepository->save($task);

        return $link;
    }

    /**
     * Links the task to a host object (`TaskLinkType::HostObject`); an existing link to the same object is returned
     * as it is.
     */
    public function attachObject(Task $task, string $objectType, string $objectId, object $user, ?string $label = null): TaskLink
    {
        foreach ($task->getLinks() as $existing) {
            if ($existing->isHostObjectOf($objectType, $objectId)) {
                return $existing;
            }
        }

        $link = TaskLink::forHostObject($task, $objectType, $objectId, $label);
        $task->addLink($link);
        $this->changeRecorder->record(
            $task,
            $user,
            TaskChangeType::LinkAdded,
            null,
            $label ?? $objectType . ' #' . $objectId,
            $objectType . ':' . $objectId,
        );
        $this->taskRepository->save($task);

        return $link;
    }

    /**
     * Removes the task's link to a host object; false when the task has none.
     */
    public function detachObject(Task $task, string $objectType, string $objectId, object $user): bool
    {
        foreach ($task->getLinks() as $link) {
            if ($link->isHostObjectOf($objectType, $objectId)) {
                return $this->remove($task, $link->getId(), $user);
            }
        }

        return false;
    }

    public function update(Task $task, string $linkId, TaskLinkFormData $data, object $user): bool
    {
        $link = $this->findLink($task, $linkId);
        if (!$link instanceof TaskLink) {
            return false;
        }

        $oldSummary = $this->linkSummary($link);

        [$linkType, $label, $externalId] = $this->resolveLinkMeta($data);

        $link
            ->setLinkType($linkType)
            ->setUrl($data->url)
            ->setLabel($label)
            ->setExternalId($externalId);

        $this->changeRecorder->record(
            $task,
            $user,
            TaskChangeType::LinkUpdated,
            $oldSummary,
            $this->linkSummary($link),
            $link->getUrl(),
        );
        $this->taskRepository->save($task);

        return true;
    }

    public function remove(Task $task, string $linkId, object $user): bool
    {
        $link = $this->findLink($task, $linkId);
        if (!$link instanceof TaskLink) {
            return false;
        }

        $summary = $this->linkSummary($link);
        $url     = $link->isHostObject() ? $link->getObjectType() . ':' . $link->getObjectId() : $link->getUrl();

        $task->removeLink($link);
        $this->changeRecorder->record(
            $task,
            $user,
            TaskChangeType::LinkRemoved,
            $summary,
            null,
            $url,
        );
        $this->taskRepository->save($task);

        return true;
    }

    /**
     * @return array{0: TaskLinkType, 1: ?string, 2: ?string}
     */
    private function resolveLinkMeta(TaskLinkFormData $data): array
    {
        $linkType   = $data->linkType;
        $label      = $data->label;
        $externalId = $data->externalId;

        $parsed = $this->gitlabMrParser->parse($data->url);
        if ($parsed !== null) {
            $linkType = TaskLinkType::MergeRequest;
            $label ??= $parsed['label'];
            $externalId ??= $parsed['externalId'];
        }

        return [$linkType, $label, $externalId];
    }

    private function findLink(Task $task, string $linkId): ?TaskLink
    {
        foreach ($task->getLinks() as $link) {
            if ($link->getId() === $linkId) {
                return $link;
            }
        }

        return null;
    }

    private function linkSummary(TaskLink $link): string
    {
        $label = $link->getLabel();
        if ($label !== null && $label !== '') {
            return $label;
        }

        return $link->isHostObject() ? $link->getObjectType() . ' #' . $link->getObjectId() : $link->getUrl();
    }
}
