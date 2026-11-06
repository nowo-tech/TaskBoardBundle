<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.23: one node of the provider's tree (only lists are selectable for ClickUp).
 */
final readonly class RemoteContainer
{
    /**
     * @param list<string> $path the names of its ancestors, top first (for a breadcrumb)
     */
    public function __construct(
        public string $id,
        public string $name,
        public ContainerType $type,
        public ?string $parentId,
        public bool $selectable,
        public bool $hasChildren,
        public ?int $taskCount = null,
        public array $path = [],
        // Since 1.28.0: the provider's description of the container, as safe Markdown.
        public ?string $description = null,
    ) {
    }
}
