<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched (since 1.11) before listing the column sets a user may apply, and before saving a board's columns as a
 * set: the scope key is the one stored on a new set and the one listed. Default scope: the board's tenant key (or the
 * tenant of a board being created), null without tenancy — a host that shares the sets across tenants sets null.
 * Listeners may also replace the listed sets entirely.
 */
final class ColumnSetQueryEvent extends Event
{
    private ?string $scopeKey;

    /** @var list<ColumnSet>|null */
    private ?array $overrideList = null;

    /**
     * @param TaskBoard|null $board The board the sets are listed for or saved from; null when creating a board
     * @param string|null $tenantKey Tenant of the request (the board's tenant, or the tenant of a board being created)
     */
    public function __construct(
        private readonly object $subject,
        private readonly ?TaskBoard $board = null,
        private readonly ?string $tenantKey = null,
    ) {
        $this->scopeKey = $board?->getTenantKey() ?? $tenantKey;
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    /**
     * @deprecated since 2.15.0, use {@see self::getBoardId()} or {@see self::getBoardSnapshot()}; removed in 3.0
     */
    public function getBoard(): ?TaskBoard
    {
        return $this->board;
    }

    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
    }

    /**
     * The scope of the listed and saved sets; null: the sets shared by every board.
     */
    public function setScopeKey(?string $scopeKey): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->scopeKey = $scopeKey;
    }

    /** @param list<ColumnSet> $sets */
    public function overrideList(array $sets): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideList = $sets;
    }

    /** @return list<ColumnSet>|null */
    public function getOverrideList(): ?array
    {
        return $this->overrideList;
    }

    /** Since 2.15.0 (REQ-ARCH-001 rule 4): the id of the board the sets are listed for; null outside a board. */
    public function getBoardId(): ?string
    {
        return $this->board?->getId();
    }

    /** Since 2.15.0: the board as a framework-free snapshot (copied on each call); null outside a board. */
    public function getBoardSnapshot(): ?TaskBoardSnapshot
    {
        return $this->board instanceof TaskBoard ? Snapshots::board($this->board) : null;
    }

    /** Since 2.15.0: the subject's `getId()` as a string ({@see ActorId}); null without one. */
    public function getSubjectId(): ?string
    {
        return ActorId::of($this->subject);
    }
}
