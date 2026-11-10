<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskLinkAddedEvent;
use Nowo\TaskBoardBundle\Event\TaskLinkRemovedEvent;
use Nowo\TaskBoardBundle\Event\TaskLinkUpdatedEvent;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Attaches external links (MR, PR, URL) and host object links to tasks. Since 2.11.0 (DDD audit G3): after each saved
 * change it dispatches {@see TaskBoardEvents::TASK_LINK_ADDED}, {@see TaskBoardEvents::TASK_LINK_UPDATED} or
 * {@see TaskBoardEvents::TASK_LINK_REMOVED} (ids only), through {@see TaskEventDeferral}. An existing host object link
 * returned as it is dispatches nothing.
 */
final readonly class TaskLinkAttacher
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private GitLabMergeRequestLinkParser $gitlabMrParser,
        private TaskChangeRecorder $changeRecorder,
        // Since 2.11.0 (DDD audit G3): dispatches TASK_LINK_ADDED / TASK_LINK_UPDATED / TASK_LINK_REMOVED (none: no event).
        private ?EventDispatcherInterface $eventDispatcher = null,
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
        $this->eventDispatcher?->dispatch(TaskLinkAddedEvent::of($link, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_LINK_ADDED);

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
        $this->eventDispatcher?->dispatch(TaskLinkAddedEvent::of($link, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_LINK_ADDED);

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
            ->setLabel($label);
        // Since 1.22: the external id of a link an import wrote is its matching key; the form cannot change it.
        if ($link->getImportSource() === null) {
            $link->setExternalId($externalId);
        }

        $this->changeRecorder->record(
            $task,
            $user,
            TaskChangeType::LinkUpdated,
            $oldSummary,
            $this->linkSummary($link),
            $link->getUrl(),
        );
        $this->taskRepository->save($task);
        $this->eventDispatcher?->dispatch(TaskLinkUpdatedEvent::of($link, $user, $this->changeRecorder->currentCause()), TaskBoardEvents::TASK_LINK_UPDATED);

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

        $event = TaskLinkRemovedEvent::of($link, $user, $this->changeRecorder->currentCause());
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
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_LINK_REMOVED);

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
