<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Http;

/**
 * Since 1.23: waits between retries (tests use a recording sleeper that does not sleep).
 */
interface SleeperInterface
{
    public function sleep(int $seconds): void;
}
