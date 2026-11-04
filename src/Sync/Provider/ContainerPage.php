<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/** Since 1.23: one page of one level of the tree. */
final readonly class ContainerPage
{
    /**
     * @param list<RemoteContainer> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {
    }
}
