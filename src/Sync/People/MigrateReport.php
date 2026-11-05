<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

/**
 * Since 1.25: one batch of {@see TaskSyncCommentLegacyAuthorMigrator::migrate()}. `migrated` comments lost their 1.23
 * prefix line and point at their person; `skipped` ones carry a prefix whose name matches zero or several people.
 * Call again with `lastId` until `done`. Holds no personal data.
 */
final readonly class MigrateReport
{
    public function __construct(
        public string $connectionId,
        public ?string $directoryId,
        public int $migrated = 0,
        public int $skipped = 0,
        public string $lastId = '',
        public bool $done = true,
    ) {
    }
}
