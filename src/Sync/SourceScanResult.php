<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use Nowo\TaskBoardBundle\Sync\People\PeopleScanResult;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteSource;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;

/**
 * Since 1.25 (§15.1): what the connection dialog shows before `link()` — the source, its workspace, its statuses
 * (for the status → column map), the people (for the People step) and the time mode. `calls` = provider requests.
 */
final readonly class SourceScanResult
{
    /**
     * @param list<RemoteStatus> $statuses
     */
    public function __construct(
        public RemoteSource $source,
        public string $workspaceId,
        public array $statuses,
        public PeopleScanResult $people,
        public ?TimeAccess $timeAccess,
        public int $calls,
    ) {
    }
}
