<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/** Since 1.23: one page of the tasks of a list. */
final readonly class RemoteTaskPage
{
    /**
     * @param list<RemoteTask> $tasks
     */
    public function __construct(
        public array $tasks,
        public ?string $nextCursor,
        public bool $lastPage,
    ) {
    }
}
