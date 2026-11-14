<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

/**
 * Since 2.20.0 (Beacon spec 234, T004): what {@see TaskReparenterInterface::reparent()} did. Ids only.
 */
final readonly class TaskReparentResult
{
    /**
     * @param string|null $previousParentId the parent before the call (null: a top-level task)
     * @param string|null $parentId the parent now (null: a top-level task)
     * @param bool $changed false when the task already had that parent: nothing was written
     */
    public function __construct(
        public string $taskId,
        public string $boardId,
        public ?string $previousParentId,
        public ?string $parentId,
        public bool $changed,
    ) {
    }
}
