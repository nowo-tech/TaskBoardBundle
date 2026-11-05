<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonPage;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;

/**
 * A provider with people but without sources or tracked time.
 */
class PeopleOnlyProvider extends PlainTaskProvider implements PeopleProviderInterface
{
    public function workspaceOf(ProviderCredential $credential, string $containerId): ?string
    {
        return $this->inner->workspaceOf($credential, $containerId);
    }

    public function fetchPeople(ProviderCredential $credential, string $workspaceId, ?string $cursor = null): RemotePersonPage
    {
        return $this->inner->fetchPeople($credential, $workspaceId, $cursor);
    }

    public function timeAccess(ProviderCredential $credential, string $workspaceId): TimeAccess
    {
        return $this->inner->timeAccess($credential, $workspaceId);
    }
}
