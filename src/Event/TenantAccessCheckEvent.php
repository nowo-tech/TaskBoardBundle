<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 3.2.0 (CU-2): dispatched as {@see TaskBoardEvents::TENANT_ACCESS_CHECK} before a tenant-level action is
 * allowed — today {@see self::ACTION_IMPORT_PLAN}. The default decision is exposed by {@see self::isDefaultGranted()};
 * a listener calls {@see self::grant()} or {@see self::deny()} (the last call wins).
 */
final class TenantAccessCheckEvent extends Event
{
    /**
     * Seeing *Import from ClickUp* and creating, editing, previewing, starting, cancelling or deleting an import plan in
     * the tenant (Q-CU-10). Default: a holder of `security.manage_roles` only (deny by default: a plan creates boards
     * and reads remote e-mails). Beacon grants it to project managers and organisation admins.
     */
    public const ACTION_IMPORT_PLAN = 'import_plan';

    private bool $granted = false;

    private bool $denied = false;

    public function __construct(
        private readonly object $subject,
        private readonly ?string $tenantKey,
        private readonly bool $defaultGranted = false,
        private readonly string $action = self::ACTION_IMPORT_PLAN,
    ) {
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubject(): object
    {
        return $this->subject;
    }

    /** The id of the subject (REQ-ARCH-001 rule 4), null when it has none. */
    public function getSubjectId(): ?string
    {
        return ActorId::of($this->subject);
    }

    /** The tenant key (null without tenancy). */
    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function isDefaultGranted(): bool
    {
        return $this->defaultGranted;
    }

    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->denied = false;
    }

    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->denied = true;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    /** The decision: a listener's grant or deny, else the default. */
    public function isAllowed(): bool
    {
        return $this->granted || (!$this->denied && $this->defaultGranted);
    }
}
