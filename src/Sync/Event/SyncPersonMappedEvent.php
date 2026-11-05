<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25: {@see TaskBoardEvents::SYNC_PERSON_MAPPED} — a remote person's mapping changed (also automatically: then
 * `actorId` is null). Ids only: no name, no e-mail. The host writes its audit row from it.
 */
final class SyncPersonMappedEvent extends Event
{
    public const REASON_AUTO           = 'auto';
    public const REASON_SUGGESTED      = 'suggested';
    public const REASON_MANUAL         = 'manual';
    public const REASON_EXTERNAL       = 'external';
    public const REASON_CONFIRM        = 'confirm';
    public const REASON_AUTO_UNMATCHED = 'auto_unmatched';
    public const REASON_ERASED         = 'erased';

    public function __construct(
        public readonly string $directoryId,
        public readonly string $personId,
        public readonly string $previousMapping,
        public readonly ?string $previousUserId,
        public readonly string $newMapping,
        public readonly ?string $newUserId,
        public readonly ?string $actorId,
        public readonly string $reason,
    ) {
    }
}
