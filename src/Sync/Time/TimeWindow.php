<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Time;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteLocation;

/**
 * Since 1.25: one window of the time phase (start inclusive, end exclusive) for one source.
 */
final readonly class TimeWindow
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public RemoteLocation $location,
    ) {
    }
}
