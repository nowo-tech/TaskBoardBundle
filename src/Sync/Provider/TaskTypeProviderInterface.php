<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.28.0 (TB-SYNC-4), optional: the task types of a workspace.
 */
interface TaskTypeProviderInterface
{
    /**
     * @return array<string, string> type id => name
     */
    public function fetchTaskTypes(ProviderCredential $credential, string $workspaceId): array;
}
