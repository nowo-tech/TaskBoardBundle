<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Sync\Dto\SyncReport;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/** Since 1.23: {@see TaskBoardEvents::SYNC_PAGE_DONE}, after a page is written (progress). */
final class SyncPageDoneEvent extends Event
{
    public function __construct(
        private readonly TaskBoard $board,
        public readonly SyncReport $progress,
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
