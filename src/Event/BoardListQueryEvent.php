<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
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
    }

    public function getSubject(): object
    {
        return $this->subject;
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
