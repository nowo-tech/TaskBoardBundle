<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Event\SyncPeopleReprojectedEvent;

use function preg_replace;
use function strtolower;
use function substr;

/**
 * Since 1.25 (§5.7 of the contract): re-projection when someone gains board access. The host calls it on its
 * membership events (normally through Messenger); the bundle listens to no host event. It turns the references of
 * every person mapped (auto / manual) to the user into real rows — creator, task members, comment authors — on the
 * boards the user can view **now** (access and candidacy re-checked inside each board's sync lock). Idempotent and
 * resumable: call again with the returned cursor until it is null. Losing access deletes nothing.
 */
final readonly class TaskSyncPeopleProjector
{
    public function __construct(
        private TaskSyncPeopleReassigner $reassigner,
    ) {
    }

    /**
     * @param string $trigger what the host reacted to, e.g. `membership_added` ([a-z0-9_.-], at most 32 characters;
     *                        carried by {@see SyncPeopleReprojectedEvent})
     */
    public function reprojectForUser(object $user, ?TaskBoard $board = null, int $batchSize = 500, ?ReassignCursor $cursor = null, string $trigger = 'membership'): ReassignReport
    {
        $trigger = substr((string) preg_replace('/[^a-z0-9_.-]+/', '', strtolower($trigger)), 0, 32);

        return $this->reassigner->reprojectForUser($user, $board, $trigger !== '' ? $trigger : 'membership', $batchSize, $cursor);
    }
}
