<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;

/**
 * Since 3.2.0 (audit ZBYv-L1): which chosen containers lie outside a workspace — through
 * {@see WorkspaceScopeProviderInterface} when the provider has it, else {@see PeopleProviderInterface::workspaceOf()}
 * per container (stopping at the first foreign one); a provider without workspaces has none. Stateless.
 */
final class WorkspaceScope
{
    /**
     * @param list<string> $containerIds
     *
     * @throws ProviderException
     *
     * @return list<string>
     */
    public static function foreign(TaskProviderInterface $provider, ProviderCredential $credential, string $workspaceId, array $containerIds): array
    {
        if ($provider instanceof WorkspaceScopeProviderInterface) {
            return $provider->foreignContainers($credential, $workspaceId, $containerIds);
        }
        if (!$provider instanceof PeopleProviderInterface) {
            return [];
        }
        foreach ($containerIds as $id) {
            if ($provider->workspaceOf($credential, $id) !== $workspaceId) {
                return [$id];
            }
        }

        return [];
    }
}
