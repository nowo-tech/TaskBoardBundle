<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see TaskBoardTargetsInterface}: the ids of the boards a
 * task may be moved or duplicated to, given a {@see TaskSnapshot}. The bundle loads the boards by id, in the order given;
 * an unknown id is skipped. Only these are offered, and any other target answers 404.
 *
 * Set `move.targets` to a service implementing it. A service that also implements the deprecated interface is asked
 * through that one until 3.0.
 */
interface TaskBoardTargetIdsInterface
{
    /**
     * @return list<string> the source's board may be among them (a duplicate on the same board); a move leaves it out
     */
    public function targetIds(TaskSnapshot $task, object $user): array;
}
