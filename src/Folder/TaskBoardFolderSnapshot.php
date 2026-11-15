<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use DateTimeImmutable;

/**
 * Since 2.19.0 (FR-031): a folder as {@see TaskBoardRestructureInterface} answers it, copied when asked. `boardIds`: the
 * boards in it (archived ones included), by name.
 */
final readonly class TaskBoardFolderSnapshot
{
    /**
     * @param list<string> $boardIds
     */
    public function __construct(
        public string $id,
        public ?string $tenantKey,
        public string $name,
        public ?string $description,
        public int $position,
        public array $boardIds,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
