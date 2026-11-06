<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.27.0 (TB-SYNC-2): {@see TaskBoardEvents::SYNC_PEOPLE_MAPPING_CHANGED} — one save of the People page (or step)
 * changed mappings. Ids only: no name or e-mail (listeners must not log the person entities). Each change also
 * dispatched its own `SYNC_PERSON_MAPPED`, and `SYNC_PERSON_REASSIGNED` once its re-application finished.
 */
final class PeopleMappingChangedEvent extends Event
{
    /**
     * @param list<array{personId: string, previousMapping: string, mapping: string, previousUserId: string|null, userId: string|null}> $changes
     * @param int $invited invitations the host's inviter sent (people still unmapped)
     * @param int $pending people whose re-application to already imported content is not finished yet
     */
    public function __construct(
        public readonly string $boardId,
        public readonly string $directoryId,
        public readonly ?string $actorId,
        public readonly array $changes,
        public readonly int $invited = 0,
        public readonly int $pending = 0,
    ) {
    }
}
