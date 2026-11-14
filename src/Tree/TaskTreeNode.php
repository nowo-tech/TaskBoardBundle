<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tree;

/**
 * Since 2.20.0 (Beacon spec 234, T004): a task's place in its board's tree as the database holds it (read under lock by
 * {@see TaskTreeStoreInterface::lockedNode()}): ids and the revision only, never an entity.
 */
final readonly class TaskTreeNode
{
    public function __construct(
        public string $id,
        public string $boardId,
        public ?string $parentId,
        public int $revision,
    ) {
    }
}
