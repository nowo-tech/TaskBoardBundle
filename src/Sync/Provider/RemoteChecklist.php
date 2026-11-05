<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a checklist of a task; each item becomes a subtask.
 */
final readonly class RemoteChecklist
{
    /**
     * @param list<RemoteChecklistItem> $items
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $position = 0,
        public array $items = [],
    ) {
    }
}
