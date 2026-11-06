<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use DateTimeImmutable;

use function array_filter;
use function array_map;

use const DATE_ATOM;

/**
 * Since 1.27.0 (TB-SYNC-3): what a provider task has besides the import row, kept read-only on the synced task (the
 * task panel's "From <provider>" section) — validated by the adapter.
 */
final readonly class RemoteTaskDetails
{
    /**
     * @param list<string> $path the source location, outermost first (space, folder, list)
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
    ) {
    }

    /**
     * @return array<string, mixed> the JSON stored on the sync item (no personal data beyond names)
     */
    public function toArray(): array
    {
        return array_filter([
            'customId'     => $this->customId,
            'points'       => $this->points,
            'doneAt'       => $this->doneAt?->format(DATE_ATOM),
            'closedAt'     => $this->closedAt?->format(DATE_ATOM),
            'startHasTime' => $this->startHasTime ?: null,
            'dueHasTime'   => $this->dueHasTime ?: null,
            'path'         => $this->path === [] ? null : $this->path,
            'estimates'    => $this->estimates === [] ? null : array_map(static fn (array $e): array => ['id' => $e['person']->providerUserId, 'name' => $e['person']->name, 'minutes' => $e['minutes']], $this->estimates),
            'recurrence'   => $this->recurrence,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
