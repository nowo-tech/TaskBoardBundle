<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/** Since 1.23: a field kept here although the provider changed it too (preview at most 200 bytes). */
final readonly class SyncConflict
{
    public function __construct(
        public string $taskId,
        public string $taskTitle,
        public string $externalId,
        public string $field,
        public string $remotePreview,
    ) {
    }

    /**
     * @return array{taskId: string, taskTitle: string, externalId: string, field: string, remotePreview: string}
     */
    public function toArray(): array
    {
        return ['taskId' => $this->taskId, 'taskTitle' => $this->taskTitle, 'externalId' => $this->externalId, 'field' => $this->field, 'remotePreview' => $this->remotePreview];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(SyncValues::string($data['taskId'] ?? ''), SyncValues::string($data['taskTitle'] ?? ''), SyncValues::string($data['externalId'] ?? ''), SyncValues::string($data['field'] ?? ''), SyncValues::string($data['remotePreview'] ?? ''));
    }
}
