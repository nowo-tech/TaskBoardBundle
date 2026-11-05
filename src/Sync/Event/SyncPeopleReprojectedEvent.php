<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Event;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\People\ReassignReport;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.25: {@see TaskBoardEvents::SYNC_PEOPLE_REPROJECTED} — a user who gained board access had the references of
 * the people mapped to them turned into real rows (creator, members, comment authors).
 */
final class SyncPeopleReprojectedEvent extends Event
{
    /**
     * @param list<string> $boardIds
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $boardIds,
        public readonly ReassignReport $report,
        public readonly string $trigger,
    ) {
    }
}
