<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

/**
 * Since 2.16.0 (spec 234, REQ-ARCH-001 rule 3): a board column as the framework-free host ports see it, copied when the
 * port is called. No entity, no collection.
 *
 * `$last`: the last column by position. Since 2.19.0 `$type` is the column's type (`open`, `custom`, `done`, `closed`,
 * Beacon spec 234 FR-033): a task in a `done` or `closed` column is done. On a board none of whose columns carries a
 * type it follows the position (the last column is `closed`), so `$last` and `$type` agree there.
 */
final readonly class BoardColumnSnapshot
{
    public function __construct(
        public string $id,
        public string $boardId,
        public string $name,
        public int $position,
        public bool $humansOnly,
        public bool $last,
        // Since 2.19.0 (FR-033); null only when built by code that does not know it.
        public ?string $type = null,
    ) {
    }
}
