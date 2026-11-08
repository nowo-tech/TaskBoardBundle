<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): what an idempotency store answers for a key.
 */
enum TaskApiIdempotencyOutcome
{
    /** The caller goes ahead and then completes or releases the claim. */
    case Claimed;

    /** The first attempt finished: `$status` and `$body` are its answer. */
    case Completed;

    /** The first attempt is still running. */
    case InFlight;

    /** The key was used for a different request. */
    case Mismatch;
}
