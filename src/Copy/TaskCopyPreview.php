<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Copy;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.3.0 (spec 217, FR-017): what a duplicate will write and, first, what does not carry over. Counts, never content.
 * `fingerprint` changes when any of these facts does, so a confirmation of an older preview is refused.
 */
final readonly class TaskCopyPreview
{
    /**
     * @param list<string> $membersNotCopied labels of the members who cannot be assigned on the target
     */
    public function __construct(
        public TaskBoard $targetBoard,
        public ?BoardColumn $column,
        public int $tasks,
        public int $membersCopied,
        public array $membersNotCopied,
        public int $comments,
        public int $timeEntries,
        public int $trackedSeconds,
        public int $dependencies,
        public int $links,
        public bool $synced,
        public string $fingerprint,
    ) {
    }

    /** The subtasks that come with the copy. */
    public function subtasks(): int
    {
        return $this->tasks - 1;
    }
}
