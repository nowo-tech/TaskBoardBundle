<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

/**
 * Since 2.15.0 (REQ-ARCH-001 rules 1 and 3): a provider people directory as the framework-free sync ports see it.
 */
final readonly class TaskSyncDirectorySnapshot
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $scopeKey,
        public string $workspaceId,
    ) {
    }
}
