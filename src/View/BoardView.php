<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use DateTimeImmutable;

/**
 * Since 2.29.0 (DDD-host checklist B7, REQ-ARCH-001 rules 1 and 3): one board as the id-based host ports return it.
 * Ids, scalars and dates only.
 */
final readonly class BoardView
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $tenantKey,
        public ?string $creatorId,
        public ?DateTimeImmutable $archivedAt,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof DateTimeImmutable;
    }
}
