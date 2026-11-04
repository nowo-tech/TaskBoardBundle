<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

/**
 * Since 1.23: the host's plan limits refuse a page or a file. `code` is shown through
 * `task_board.sync.refusal.<code>` (or the host's own `messageKey`).
 */
final readonly class LimitRefusal
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public string $code,
        public ?string $messageKey = null,
        public array $params = [],
        public int $overTasks = 0,
        public int $overBytes = 0,
    ) {
    }
}
