<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: a provider that lists the people of a workspace (optional; detected with `instanceof` and
 * {@see ProviderCapabilities::$people}). Read-only; every method may throw a ProviderException.
 */
interface PeopleProviderInterface
{
    /** The workspace a container (list "<id>", folder "f:<id>", space "s:<id>") belongs to; null when not found. */
    public function workspaceOf(ProviderCredential $credential, string $containerId): ?string;

    /** Workspace members (and guests when the token may list them), page by page. */
    public function fetchPeople(ProviderCredential $credential, string $workspaceId, ?string $cursor = null): RemotePersonPage;

    /** Whether the token may read everyone's time (admin) or its own (member). */
    public function timeAccess(ProviderCredential $credential, string $workspaceId): TimeAccess;
}
