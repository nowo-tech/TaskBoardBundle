<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use function in_array;

/** Since 1.23: how a run (or its last slice) ended. */
enum SyncStatus: string
{
    case Running     = 'running';
    case Succeeded   = 'succeeded';
    case Paused      = 'paused';
    case RateLimited = 'rate_limited';
    case Refused     = 'refused';
    case Failed      = 'failed';
    case Busy        = 'busy';
    case Cancelled   = 'cancelled';

    /** Whether nothing more will happen without a new request. */
    public function isTerminal(): bool
    {
        return !$this->isResumable() && $this !== self::Running;
    }

    /** Whether the run can continue from its cursor. */
    public function isResumable(): bool
    {
        return in_array($this, [self::Paused, self::RateLimited, self::Refused], true);
    }
}
