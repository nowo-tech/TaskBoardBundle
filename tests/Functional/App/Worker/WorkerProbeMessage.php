<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App\Worker;

/**
 * Since 2.11.1 (REQ-RUNTIME-002): a Messenger message of the worker-state test, handled for one user's board.
 */
final readonly class WorkerProbeMessage
{
    public function __construct(
        public string $email,
        public string $boardId,
        public bool $fail,
    ) {
    }
}
