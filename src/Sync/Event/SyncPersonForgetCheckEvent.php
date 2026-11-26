<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 3.4.0 (Beacon spec 208 E, owner decision 2026-11-26): {@see TaskBoardEvents::SYNC_PERSON_FORGET_CHECK}, before
 * the People page offers "Forget this person" (`$personId` null: may the action show on this board's page at all) and
 * again before a forget runs (`$personId` set). **Denied by default**: nothing happens unless a listener calls
 * {@see self::allow()}; a {@see self::deny()} sticks. The person must manage the board's connection too (checked by the
 * bundle before this event). Ids only: no name, e-mail or provider id of the person is in it.
 *
 * Beacon grants it to the project's and the organisation's managers.
 */
final class SyncPersonForgetCheckEvent extends Event
{
    private bool $allowed = false;

    private bool $denied = false;

    public function __construct(
        public readonly object $actor,
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly string $directoryId,
        public readonly ?string $personId = null,
    ) {
    }

    public function allow(): void
    {
        // @igor-ignore - An event object created per dispatch, not a shared service.
        $this->allowed = true;
    }

    public function deny(): void
    {
        // @igor-ignore - An event object created per dispatch, not a shared service.
        $this->denied = true;
    }

    /** Allowed by a listener and denied by none. */
    public function isAllowed(): bool
    {
        return $this->allowed && !$this->denied;
    }
}
