<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Http;

use function sleep;

/**
 * Since 1.23: {@see SleeperInterface} with PHP's sleep().
 *
 * @codeCoverageIgnore a real wait; the tests use a recording sleeper
 */
final readonly class SystemSleeper implements SleeperInterface
{
    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
