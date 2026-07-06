<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Dto;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

/**
 * Normalized task row produced by any importer.
 */
final readonly class ImportedTaskDto
{
    public function __construct(
        public string $title,
        public ?string $externalId = null,
        public ?string $parentExternalId = null,
        public ?string $status = null,
        public TaskPriority $priority = TaskPriority::Normal,
        public ?string $description = null,
        public ?string $assigneeEmail = null,
        public ?DateTimeImmutable $dueAt = null,
        public ?int $estimatedMinutes = null,
        /** @var list<string> */
        public array $tags = [],
        public ?string $sourceUrl = null,
    ) {
    }
}
