<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.0.0: asked before a board or tasks are written (`TaskBoardEvents::QUOTA_CHECK`), so a host enforces its plan
 * limits without intercepting routes. `deny()` refuses the write: nothing is written and the reason is shown to the user,
 * so the host passes it already translated. No listener: no change.
 *
 * `$board` is null for `board_create`. `$count` is what the write adds: 1 board, 1 task, or the tasks an import would
 * create (rows matching an existing task add nothing).
 */
final class TaskBoardQuotaCheckEvent extends Event
{
    public const ACTION_BOARD_CREATE = 'board_create';

    public const ACTION_TASK_CREATE = 'task_create';

    public const ACTION_IMPORT = 'import';

    private ?string $reason = null;

    public function __construct(
        public readonly string $action,
        public readonly ?string $tenantKey,
        public readonly ?TaskBoard $board,
        public readonly int $count,
        public readonly object $actor,
    ) {
    }

    public function deny(string $reason): void
    {
        $this->reason = $reason;
        $this->stopPropagation();
    }

    public function isDenied(): bool
    {
        return $this->reason !== null;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
