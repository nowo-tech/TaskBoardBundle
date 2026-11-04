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
    ) {
    }

    public function externalId(): string
    {
        return (string) $this->task->externalId;
    }
}
