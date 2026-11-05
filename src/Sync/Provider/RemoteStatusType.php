<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: the kind of a remote status. `closed` and `done` mean the task is completed.
 */
enum RemoteStatusType: string
{
    case Open   = 'open';
    case Custom = 'custom';
    case Closed = 'closed';
    case Done   = 'done';

    public function isDone(): bool
    {
        return $this === self::Closed || $this === self::Done;
    }
}
