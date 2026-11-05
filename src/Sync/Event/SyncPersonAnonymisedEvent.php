<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25: {@see TaskBoardEvents::SYNC_PERSON_ANONYMISED} — "Forget this person" (or an erasure) cleared a remote
 * person's name and e-mail.
 */
final class SyncPersonAnonymisedEvent extends Event
{
    public function __construct(
        public readonly string $directoryId,
        public readonly string $personId,
        public readonly ?string $actorId,
    ) {
    }
}
