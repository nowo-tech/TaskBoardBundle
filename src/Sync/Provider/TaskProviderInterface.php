<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;

/**
 * Since 1.23: a task tool the board can be linked to (ClickUp first). Implementations are tagged
 * `nowo_task_board.task_provider` (autoconfigured) and found through {@see TaskProviderRegistry}. Every method may
 * throw {@see ProviderException}; none may put the credential in an exception, a log line or a return value.
 */
interface TaskProviderInterface
{
    /** Stable id, [a-z0-9_]{1,32}: "clickup". Also the default import scope. */
    public function id(): string;

    /** Translation key of the provider name (domain NowoTaskBoardBundle). */
    public function labelKey(): string;

    public function capabilities(): ProviderCapabilities;

    /** The `TaskLink.import_source` written and matched (ClickUp: "clickup", shared with the file imports). */
    public function importScope(ProviderCredential $credential): string;

    /** One level of the remote tree; `$parentId` null = the top level (ClickUp: workspaces). */
    public function listContainers(ProviderCredential $credential, ?string $parentId = null, ?string $cursor = null): ContainerPage;

    /** A selectable container (a list) by id, with its breadcrumb; null when it does not exist or is not a list. */
    public function findContainer(ProviderCredential $credential, string $containerId): ?RemoteContainer;

    /**
     * Tasks of a list (subtasks and closed ones included), oldest update first, one page per call. `$updatedSince`
     * null = every task; `$cursor` = {@see RemoteTaskPage::$nextCursor} of the previous call (null = first page).
     */
    public function fetchTasks(ProviderCredential $credential, string $listId, ?DateTimeImmutable $updatedSince, ?string $cursor): RemoteTaskPage;

    /**
     * @return iterable<RemoteComment> every comment of a task, oldest first (the adapter pages internally)
     */
    public function fetchComments(ProviderCredential $credential, string $taskId): iterable;

    /**
     * Ids of every task still in the list (not archived remotely). Must throw on any failure: a partial answer must
     * never look complete.
     *
     * @return iterable<string>
     */
    public function existingTaskIds(ProviderCredential $credential, string $listId): iterable;
}
