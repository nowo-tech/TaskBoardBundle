<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\Sync\Http\SleeperInterface;

/** Since 1.23: records the waits instead of sleeping. */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<int> */
    public array $waits = [];

    public function sleep(int $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
