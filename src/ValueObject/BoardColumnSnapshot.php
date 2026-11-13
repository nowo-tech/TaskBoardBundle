<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

/**
 * Since 2.16.0 (spec 234, REQ-ARCH-001 rule 3): a board column as the framework-free host ports see it, copied when the
 * port is called. No entity, no collection.
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
    ) {
    }
}
