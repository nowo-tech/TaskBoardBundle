<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2): the state of an import plan. `draft` while the wizard is filled; `queued`, `running`, `paused`
 * and `rate_limited` while it imports (the active states: one per tenant and workspace); then a final state.
 */
enum ImportPlanStatus: string
{
    case Draft       = 'draft';
    case Queued      = 'queued';
    case Running     = 'running';
    case Paused      = 'paused';
    case RateLimited = 'rate_limited';
    case Succeeded   = 'succeeded';
    case Failed      = 'failed';
    case Cancelled   = 'cancelled';

    /** Importing or waiting to go on: the workspace is busy for the tenant. */
    public function isActive(): bool
    {
        return match ($this) {
            self::Queued, self::Running, self::Paused, self::RateLimited => true,
            default                                                      => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            default                                        => false,
        };
    }
}
