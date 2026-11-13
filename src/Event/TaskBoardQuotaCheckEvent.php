<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
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

    /**
     * Since 2.3.0 (spec 217): open tasks moving into `$board` from another board (`$count` = how many). A listener that only
     * knows the actions above lets it through.
     */
    public const ACTION_TASK_MOVE = 'task_move';

    private ?string $reason = null;

    public function __construct(
        public readonly string $action,
        public readonly ?string $tenantKey,
        /** @deprecated since 2.15.0, use getBoardId() or getBoardSnapshot(); removed in 3.0 */
        public readonly ?TaskBoard $board,
        public readonly int $count,
        public readonly object $actor,
    ) {
    }

    public function deny(string $reason): void
    {
        // @igor-ignore - An event object, one per dispatch; not a shared service.
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

    /** Since 2.15.0 (REQ-ARCH-001 rule 4): the board's id; null when the action creates a board. */
    public function getBoardId(): ?string
    {
        return $this->board?->getId();
    }

    /** Since 2.15.0: the board as a framework-free snapshot (copied on each call); null when the action creates a board. */
    public function getBoardSnapshot(): ?TaskBoardSnapshot
    {
        return $this->board instanceof TaskBoard ? Snapshots::board($this->board) : null;
    }

    /** Since 2.15.0: the actor's `getId()` as a string ({@see ActorId}); null without one. */
    public function getActorId(): ?string
    {
        return ActorId::of($this->actor);
    }
}
