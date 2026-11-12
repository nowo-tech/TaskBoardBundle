<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Sync\Dto\SyncError;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/** Since 1.23: {@see TaskBoardEvents::SYNC_FAILED}. */
final class SyncFailedEvent extends Event
{
    public function __construct(
        /** @deprecated since 2.15.0, use getBoardId(), getTenantKey() or getBoardSnapshot(); removed in 3.0 */
        public readonly TaskBoard $board,
        public readonly SyncError $error,
        public readonly SyncReport $report,
    ) {
    }

    /** Since 2.15.0 (REQ-ARCH-001 rule 4): the board's id. */
    public function getBoardId(): string
    {
        return $this->board->getId();
    }

    /** Since 2.15.0: the board's tenant key (null without tenancy). */
    public function getTenantKey(): ?string
    {
        return $this->board->getTenantKey();
    }

    /** Since 2.15.0: the board as a framework-free snapshot, copied on each call. */
    public function getBoardSnapshot(): TaskBoardSnapshot
    {
        return Snapshots::board($this->board);
    }
}
