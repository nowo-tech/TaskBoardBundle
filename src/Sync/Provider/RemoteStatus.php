<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a status of a source, in the provider's order.
 */
final readonly class RemoteStatus
{
    public function __construct(
        public string $name,
        public ?RemoteStatusType $type = null,
        public ?string $color = null,
        public int $position = 0,
        public ?int $openCount = null,
    ) {
    }
}
