<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use function array_filter;
use function count;
use function in_array;
use function intdiv;
use function max;

/**
 * Since 3.2.0 (CU-2, design §8.7): what a plan would import — counts read from the provider (no write), the requests
 * it needs and how long that takes at the token's rate, the host's quota answer and the warnings. Counts only.
 */
final readonly class PlanPreview
{
    public const WARNING_TIME_MEMBER       = 'time_member_mode';
    public const WARNING_WORKSPACE_BEFORE  = 'workspace_imported_before';
    public const WARNING_WORKSPACE_BUSY    = 'workspace_busy';
    public const WARNING_ESTIMATED         = 'estimated';
    public const WARNING_PEOPLE_UNREVIEWED = 'people_unreviewed';

    /**
     * @param list<array{itemId: string, label: string, board: string, newBoard: bool, tasks: int, subtasks: int, closed: int, archived: int, attachments: int, attachmentBytes: int, columns: int, estimated: bool, error: string|null}> $items
     * @param list<string> $warnings
     * @param array{mapped: int, unreviewed: int, total: int} $people
     */
    public function __construct(
        public array $items,
        public int $requests,
        public int $perMinute,
        public array $warnings = [],
        public ?string $refusal = null,
        public array $people = ['mapped' => 0, 'unreviewed' => 0, 'total' => 0],
    ) {
    }

    /** Minutes the import takes at `perMinute` requests a minute (at least one). */
    public function minutes(): int
    {
        return max(1, intdiv($this->requests + $this->perMinute - 1, max(1, $this->perMinute)));
    }

    public function total(string $key): int
    {
        $sum = 0;
        foreach ($this->items as $item) {
            $sum += (int) $item[$key];
        }

        return $sum;
    }

    public function boardsToCreate(): int
    {
        return count(array_filter($this->items, static fn (array $item): bool => $item['newBoard']));
    }

    public function hasWarning(string $code): bool
    {
        return in_array($code, $this->warnings, true);
    }
}
