<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Import\Dto\ImportedTaskDto;

/**
 * Since 1.23: a provider task — the import row (its `externalId` is required) plus what only a sync uses.
 */
final readonly class RemoteTask
{
    /**
     * @param list<RemoteAttachment> $attachments
     * @param list<string> $inlineImageUrls images found in the description
     * @param string|null $customFieldsText the custom fields as Markdown (kept in a "Fields" document of the task)
     */
    public function __construct(
        public ImportedTaskDto $task,
        public DateTimeImmutable $remoteUpdatedAt,
        public bool $archived = false,
        public array $attachments = [],
        public array $inlineImageUrls = [],
        public ?string $customFieldsText = null,
        /**
         * Since 1.25: the watchers; `watchersKnown` false = the answer had no watchers key (unknown, not empty).
         *
         * @var list<RemotePersonRef>
         */
        public array $watchers = [],
        public bool $watchersKnown = false,
        /**
         * Since 1.25: the checklists (each item becomes a subtask); `checklistsKnown` as for watchers.
         *
         * @var list<RemoteChecklist>
         */
        public array $checklists = [],
        public bool $checklistsKnown = false,
        /** Since 1.25: total tracked seconds the provider reports on the task (null = unknown). */
        public ?int $timeSpentSeconds = null,
        /** Since 1.25: the task's status with its type (null = unknown; then the 1.23 column rules apply). */
        public ?RemoteStatus $status = null,
        /** Since 1.25: the list of the task (sources with several lists). */
        public ?string $listId = null,
        public ?string $listName = null,
    ) {
    }

    public function externalId(): string
    {
        return (string) $this->task->externalId;
    }
}
