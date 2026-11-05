<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use RuntimeException;

/**
 * Since 1.25: another re-association of the person is unfinished; resume it with the same target, or call
 * {@see TaskSyncPeopleReassigner::cancelReassign()} first.
 */
final class ReassignInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('reassign_in_progress');
    }
}
