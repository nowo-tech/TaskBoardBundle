<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: one checklist item (nested items are flattened in tree order by the adapter).
 */
final readonly class RemoteChecklistItem
{
    public function __construct(
        public string $id,
        public string $name,
        public int $position,
        public bool $resolved = false,
        public ?RemotePersonRef $assignee = null,
        public ?string $parentItemId = null,
    ) {
    }
}
