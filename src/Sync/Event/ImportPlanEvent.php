<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 3.2.0 (CU-2): an import plan started ({@see TaskBoardEvents::SYNC_IMPORT_PLAN_STARTED}), ended
 * ({@see TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED}: succeeded or cancelled) or failed
 * ({@see TaskBoardEvents::SYNC_IMPORT_PLAN_FAILED}). Ids and counts only (no names, no e-mails, no credential), so the
 * host can notify the actor (Beacon: NotifyKit). Dispatched after the plan row is saved.
 */
final class ImportPlanEvent extends Event
{
    /**
     * @param list<string> $boardIds the plan's boards (created or chosen)
     * @param array<string, int> $counts created, updated, unchanged, skipped, failed items, …
     */
    public function __construct(
        public readonly string $planId,
        public readonly ?string $tenantKey,
        public readonly string $provider,
        public readonly ?string $workspaceId,
        public readonly string $status,
        public readonly ?string $actorId,
        public readonly array $boardIds = [],
        public readonly array $counts = [],
        public readonly ?string $errorCode = null,
    ) {
    }
}
