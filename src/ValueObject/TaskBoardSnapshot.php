<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use DateTimeImmutable;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): a board as the framework-free host ports see it, copied when the port is
 * called. It holds no entity, no collection and no user object: the creator is its id (`getId()` as a string) and its
 * name snapshot.
 */
final readonly class TaskBoardSnapshot
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $tenantKey,
        public ?string $creatorId,
        public ?string $creatorName,
        public bool $archived,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
