<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a sync source (list, folder or space) with what the dialog shows.
 */
final readonly class RemoteSource
{
    /**
     * @param list<string> $path breadcrumb labels above the source
     */
    public function __construct(
        public RemoteLocation $location,
        public string $name,
        public ?string $workspaceId = null,
        public array $path = [],
        public ?int $listCount = null,
        public ?int $openTaskCount = null,
    ) {
    }

    /** The container the 1.23 link API takes. */
    public function container(): RemoteContainer
    {
        return new RemoteContainer($this->location->containerId(), $this->name, match ($this->location->kind) {
            RemoteLocationKind::List   => ContainerType::List,
            RemoteLocationKind::Folder => ContainerType::Folder,
            RemoteLocationKind::Space  => ContainerType::Space,
        }, null, true, false, $this->openTaskCount, $this->path);
    }
}
