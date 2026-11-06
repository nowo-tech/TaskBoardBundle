<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

/**
 * Since 1.27.0 (TB-SYNC-2): what one save of the People page did. `pending` people still have content to move (the time
 * budget ran out or a board was busy): the next save, or the next sync of a board of the directory, finishes it.
 */
final readonly class PeopleMappingResult
{
    /**
     * @param list<array{personId: string, previousMapping: string, mapping: string, previousUserId: string|null, userId: string|null}> $changes
     * @param list<string> $errors translation keys of refused rows (`not_candidate`, …), one per row
     */
    public function __construct(
        public array $changes = [],
        public int $invited = 0,
        public int $pending = 0,
        public array $errors = [],
        public ReassignReport $moved = new ReassignReport(),
    ) {
    }
}
