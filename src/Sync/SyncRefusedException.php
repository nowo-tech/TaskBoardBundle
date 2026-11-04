<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync;

use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use RuntimeException;

/**
 * @internal since 1.23: the host's limit refused a page (nothing of it was written)
 */
final class SyncRefusedException extends RuntimeException
{
    public function __construct(
        public readonly LimitRefusal $refusal,
    ) {
        parent::__construct('The sync was refused by a limit: ' . $refusal->code . '.');
    }
}
