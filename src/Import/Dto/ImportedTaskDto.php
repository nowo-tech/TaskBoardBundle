<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Dto;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
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
        /** Since 1.18: when the task starts (the Gantt bar's left end). */
        public ?DateTimeImmutable $startAt = null,
        /**
         * Since 1.18: the Gantt relations of this task, as [other row's external id, type]; "blocked_by X" means X has
         * to finish first.
         *
         * @var list<array{0: string, 1: TaskDependencyType}>
         */
        public array $dependsOn = [],
        /**
         * Since 1.22: when the task was created in the source tool. It has no task field to go to (the task's own
         * creation date is the audit time of the import); it only tells apart rows without an id (fingerprint).
         */
        public ?DateTimeImmutable $createdAt = null,
        /**
         * Since 1.22: assignees given by name (no e-mail), resolved through the board's assignee provider by display
         * name when exactly one user matches.
         *
         * @var list<string>
         */
        public array $assigneeNames = [],
        /**
         * Since 1.22: false when the source row has no priority (then `priority` is the default and a re-import keeps
         * the task's priority). Custom importers that leave it out keep the old meaning: the priority is the source's.
         */
        public bool $hasPriority = true,
    ) {
    }
}
