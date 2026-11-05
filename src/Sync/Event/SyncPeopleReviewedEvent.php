<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25: {@see TaskBoardEvents::SYNC_PEOPLE_REVIEWED} — the People step of a directory was saved.
 */
final class SyncPeopleReviewedEvent extends Event
{
    /**
     * @param array<string, int> $counts mapping state => people
     */
    public function __construct(
        public readonly string $directoryId,
        public readonly ?string $actorId,
        public readonly array $counts,
    ) {
    }
}
