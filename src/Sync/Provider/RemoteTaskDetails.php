<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\SyncTaskDetailsWriter;

use function array_filter;

use const DATE_ATOM;

/**
 * Since 1.27.0 (TB-SYNC-3): what a provider task has besides the import row, kept read-only on the synced task (the
 * task panel's "From <provider>" section) — validated by the adapter.
 */
final readonly class RemoteTaskDetails
{
    /**
     * @param list<string> $path the source location, outermost first (folder, list)
     * @param list<array{person: RemotePersonRef, minutes: int}> $estimates the time estimate per assignee
     */
    public function __construct(
        /** The provider's own task reference (e.g. ClickUp custom task ids, "DEV-123"). */
        public ?string $customId = null,
        /** Sprint points (a decimal string). */
        public ?string $points = null,
        public ?DateTimeImmutable $doneAt = null,
        public ?DateTimeImmutable $closedAt = null,
        /** Whether start / due carry a time of day (false = a date only). */
        public bool $startHasTime = false,
        public bool $dueHasTime = false,
        public array $path = [],
        public array $estimates = [],
        /** A read-only description of the recurrence, when the provider gives one. */
        public ?string $recurrence = null,
        /** Since 1.28.0: the provider's task type id; its name comes from {@see TaskTypeProviderInterface}. */
        public ?string $taskTypeId = null,
        /** Since 1.28.0: the workspace the task answer names. */
        public ?string $workspaceId = null,
        /**
         * Since 1.28.0: every list the task appears in.
         *
         * @var list<array{id: string, name: string}>
         */
        public array $locations = [],
        /**
         * Since 1.28.0: tag name => colours.
         *
         * @var array<string, array{fg: string|null, bg: string|null}>
         */
        public array $tagColours = [],
    ) {
    }

    /**
     * @return array<string, mixed> the JSON stored on the sync item, without `customId` (its own column) and `estimates`:
     *                              people are stored as references by {@see SyncTaskDetailsWriter} (no name, no e-mail)
     */
    public function toArray(): array
    {
        return array_filter([
            'points'       => $this->points,
            'doneAt'       => $this->doneAt?->format(DATE_ATOM),
            'closedAt'     => $this->closedAt?->format(DATE_ATOM),
            'startHasTime' => $this->startHasTime ?: null,
            'dueHasTime'   => $this->dueHasTime ?: null,
            'path'         => $this->path === [] ? null : $this->path,
            'recurrence'   => $this->recurrence,
            'locations'    => $this->locations === [] ? null : $this->locations,
            'tagColours'   => $this->tagColours === [] ? null : $this->tagColours,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
