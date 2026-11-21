<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

/**
 * Since 2.29.0: one page of a query of the host read port, and the total over every page.
 */
final readonly class TaskPage
{
    /**
     * @param list<TaskView> $items
     * @param int $total the number of matches, all pages together
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $offset,
        public int $limit,
    ) {
    }

    /** Whether another page follows this one. */
    public function hasMore(): bool
    {
        return $this->offset + $this->limit < $this->total;
    }
}
