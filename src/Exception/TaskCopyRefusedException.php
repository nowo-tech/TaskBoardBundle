<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use RuntimeException;

/**
 * Since 2.3.0 (specs 215, 217): a copy or a move was refused before anything was written. `reason` is a key under
 * `task_board.copy.refusal.` (`subtree_too_large`, `same_board`, `board_archived`, `sync_running`, `stale`, `quota`,
 * `limit`, `target_not_offered`, `synced_task`); `message` is the host's reason for `quota`.
 */
final class TaskCopyRefusedException extends RuntimeException
{
    /**
     * @param array<string, int|string> $params
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $params = [],
        ?string $hostMessage = null,
    ) {
        parent::__construct($hostMessage ?? $reason);
    }

    public function hostMessage(): ?string
    {
        return $this->getMessage() !== $this->reason ? $this->getMessage() : null;
    }
}
