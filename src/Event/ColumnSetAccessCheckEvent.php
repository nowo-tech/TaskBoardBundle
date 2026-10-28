<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched (since 1.11) before deleting a column set. The default decision (its creator or a `security.manage_roles`
 * role) is exposed via isDefaultGranted(); listeners may override it with grant() / deny().
 */
final class ColumnSetAccessCheckEvent extends Event
{
    private ?bool $decision = null;

    public function __construct(
        private readonly object $subject,
        private readonly ColumnSet $columnSet,
        private readonly bool $defaultGranted = false,
    ) {
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    public function getColumnSet(): ColumnSet
    {
        return $this->columnSet;
    }

    public function isDefaultGranted(): bool
    {
        return $this->defaultGranted;
    }

    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->decision = true;
    }

    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->decision = false;
    }

    /**
     * The final decision: a listener's grant() / deny(), else the default one.
     */
    public function isGranted(): bool
    {
        return $this->decision ?? $this->defaultGranted;
    }
}
