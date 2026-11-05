<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingDeniedException;
use Nowo\TaskBoardBundle\Sync\People\PeopleScanResult;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\Provider\PeopleProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocationKind;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\SourceProviderInterface;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;

/**
 * Since 1.25 (§15.1, Beacon Q-B14): the connection dialog's pre-scan of a source (`list`, `folder` or `space`)
 * before `link()`: the workspace, the statuses with their types, the people (with `boardAccess` per matched user)
 * and the time mode. Read-only on the provider; it persists only the draft people directory, and only when asked.
 * Authorisation: `canManageBoardConnection` on the board (which also asks the `import_tasks` decision).
 */
final readonly class TaskSyncSourceScanner
{
    public function __construct(
        private TaskSyncPeopleScanner $peopleScanner,
        private TaskBoardResourceAccessChecker $accessChecker,
    ) {
    }

    /**
     * @throws PeopleMappingDeniedException the actor may not manage the board's connection
     * @throws ProviderException `not_found` when the source is unknown or its workspace cannot be found
     */
    public function scan(TaskBoard $board, TaskProviderInterface $provider, ProviderCredential $credential, RemoteLocation $location, object $actor, bool $persistPeople = true): SourceScanResult
    {
        if (!$this->accessChecker->canManageBoardConnection($actor, $board)) {
            throw new PeopleMappingDeniedException();
        }
        $calls  = 0;
        $source = null;
        if ($provider instanceof SourceProviderInterface) {
            $source = $provider->findSource($credential, $location->containerId());
            ++$calls;
        }
        if (!$provider instanceof SourceProviderInterface && $location->kind === RemoteLocationKind::List) {
            $container = $provider->findContainer($credential, $location->id);
            ++$calls;
            $source = $container instanceof RemoteContainer ? new RemoteSource($location, $container->name, null, $container->path) : null;
        }
        if (!$source instanceof RemoteSource) {
            throw new ProviderException(ProviderErrorCode::NotFound);
        }
        $workspaceId = $source->workspaceId;
        if ($workspaceId === null && $provider instanceof PeopleProviderInterface) {
            $workspaceId = $provider->workspaceOf($credential, $location->containerId());
            ++$calls;
        }
        $statuses = [];
        if ($provider instanceof SourceProviderInterface) {
            $statuses = $provider->listStatuses($credential, $source);
            ++$calls;
        }
        $people = $workspaceId !== null
            ? $this->peopleScanner->scan($board, $provider, $credential, $workspaceId, $location, $actor, $persistPeople)
            : new PeopleScanResult([], null, 0);

        return new SourceScanResult($source, (string) $workspaceId, $statuses, $people, $people->timeAccess, $calls + $people->calls);
    }
}
