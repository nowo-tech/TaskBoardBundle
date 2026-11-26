<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 3.2.0 (audit ZBYv-L1): a provider that checks many containers against one workspace in a bounded number of
 * calls (ClickUp: the workspace's spaces once, then one call per list or folder; a space costs nothing). Optional;
 * {@see WorkspaceScope} falls back to {@see PeopleProviderInterface::workspaceOf()} per container. May throw a
 * ProviderException.
 */
interface WorkspaceScopeProviderInterface
{
    /**
     * The containers (list "<id>", folder "f:<id>", space "s:<id>") that are not in the workspace, or not found.
     *
     * @param list<string> $containerIds
     *
     * @return list<string>
     */
    public function foreignContainers(ProviderCredential $credential, string $workspaceId, array $containerIds): array;
}
