<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Support\ActorId;
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

    /**
     * @deprecated since 2.15.0, use {@see self::getColumnSetId()} and {@see self::getColumnSetScopeKey()}; removed in 3.0
     */
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

    /** Since 2.15.0 (REQ-ARCH-001 rule 4): the column set's id. */
    public function getColumnSetId(): string
    {
        return $this->columnSet->getId();
    }

    /** Since 2.15.0: the column set's scope key (null: global). */
    public function getColumnSetScopeKey(): ?string
    {
        return $this->columnSet->getScopeKey();
    }

    /** Since 2.15.0: the column set's creator `getId()` as a string; null without one. */
    public function getColumnSetCreatorId(): ?string
    {
        return ActorId::of($this->columnSet->getCreatedBy());
    }

    /** Since 2.15.0: the subject's `getId()` as a string ({@see ActorId}); null without one. */
    public function getSubjectId(): ?string
    {
        return ActorId::of($this->subject);
    }
}
