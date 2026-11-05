<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\People\ReassignReport;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25: {@see TaskBoardEvents::SYNC_PERSON_REASSIGNED} — a re-association finished on every board of the person's
 * directory. The host moves its own data here (e.g. TimeTrack copies: remove for the previous user, upsert for the
 * new one). `newUserId` null = the person is now external.
 */
final class SyncPersonReassignedEvent extends Event
{
    public function __construct(
        public readonly string $directoryId,
        public readonly string $personId,
        public readonly ?string $previousUserId,
        public readonly ?string $newUserId,
        public readonly ?string $actorId,
        public readonly string $reassignId,
        public readonly ReassignReport $report,
    ) {
    }
}
