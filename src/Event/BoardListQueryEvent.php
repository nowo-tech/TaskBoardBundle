<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before loading boards for the current user.
 */
final class BoardListQueryEvent extends Event
{
    /** @var list<TaskBoard>|null */
    private ?array $overrideList = null;

    /**
     * @param string|null $tenantKey Tenant of the index being listed (`tenancy`, since 1.11); null outside a tenant
     */
    public function __construct(
        private readonly object $subject,
        private readonly ?string $tenantKey = null,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    private readonly DateTimeImmutable $occurredAt;

    /**
     * The user whose boards are listed.
     *
     * @deprecated since 2.29.0 (DDD-host checklist B4), use {@see self::getSubjectId()}; removed in 3.0
     */
    public function getSubject(): object
    {
        return $this->subject;
    }

    /** Since 2.29.0: the id of the user whose boards are listed (`getId()` as a string; null without a usable id). */
    public function getSubjectId(): ?string
    {
        return ActorId::of($this->subject);
    }

    /** Since 2.29.0. */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Tenant of the index being listed (since 1.11); null without tenancy or outside a tenant.
     */
    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    /** @param list<TaskBoard> $boards */
    public function overrideList(array $boards): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->overrideList = $boards;
    }

    /** @return list<TaskBoard>|null */
    public function getOverrideList(): ?array
    {
        return $this->overrideList;
    }
}
