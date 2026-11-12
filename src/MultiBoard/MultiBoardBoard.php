<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0: a board of the multi-board list after the bundle's own read check: its id, the label of the Board /
 * Project column (the host's label, else the board's name), the board's name and the tenant slug of its links.
 */
final readonly class MultiBoardBoard
{
    public function __construct(
        public string $id,
        public string $label,
        public string $name,
        public ?string $tenantSlug = null,
    ) {
    }
}
