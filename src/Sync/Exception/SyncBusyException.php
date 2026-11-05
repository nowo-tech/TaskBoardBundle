<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Exception;

use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use RuntimeException;

/**
 * Since 1.25: the board's connection is held by a running sync (its lock is live), so it cannot be unlinked now.
 * Cancel the run ({@see TaskBoardSyncService::requestCancel()}) and retry after it stopped.
 */
final class SyncBusyException extends RuntimeException
{
    public function __construct(public readonly string $boardId)
    {
        parent::__construct('The board\'s sync is running; unlink it once the run stopped.');
    }
}
