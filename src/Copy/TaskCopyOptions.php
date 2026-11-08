<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Copy;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.3.0 (specs 215, 217): what a {@see TaskCopier} copy carries. Defaults: the source's board, its subtasks, its
 * dates and its members (each still assignable).
 */
final readonly class TaskCopyOptions
{
    /**
     * @param TaskBoard|null $targetBoard null: the source's board
     * @param string|null $columnId the column of the copied task (an open column of the target); null: {@see TaskColumnRule}
     * @param string|null $title null: the source's title
     * @param bool $reschedule true: the copy takes `$startAt` / `$dueAt` (subtasks shift by as many days as the due date, else
     *                         the start); false: the dates follow `$dates`
     * @param string|null $historyContext written on the copy's "created" history row, and on its "Duplicated" row after
     *                                    "task:<source id>" (e.g. "recurring", "duplicate")
     */
    public function __construct(
        public ?TaskBoard $targetBoard = null,
        public ?string $columnId = null,
        public bool $subtasks = true,
        public bool $dates = true,
        public bool $members = true,
        public ?string $title = null,
        public bool $reschedule = false,
        public ?DateTimeImmutable $startAt = null,
        public ?DateTimeImmutable $dueAt = null,
        public ?string $historyContext = null,
        // Since 2.3.0 (spec 215): `recurrence.max_subtasks`; null: the copier's own (`move.max_subtasks`).
        public ?int $maxSubtasks = null,
    ) {
    }
}
