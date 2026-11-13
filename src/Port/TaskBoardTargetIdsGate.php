<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;

use function array_values;

/**
 * Since 2.15.0: asks a host {@see TaskBoardTargetIdsInterface} where the bundle asks the deprecated {@see TaskBoardTargetsInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskBoardTargetIdsGate implements TaskBoardTargetsInterface
{
    public function __construct(
        private TaskBoardTargetIdsInterface $targets,
        private TaskBoardRepositoryInterface $boards,
    ) {
    }

    /** The boards of the ids, in their order; unknown and repeated ids are skipped. */
    public function targets(Task $task, object $user): array
    {
        $boards = [];
        foreach ($this->targets->targetIds(Snapshots::task($task), $user) as $id) {
            if (isset($boards[$id])) {
                continue;
            }
            $board = $this->boards->findById($id);
            if ($board instanceof TaskBoard) {
                $boards[$id] = $board;
            }
        }

        return array_values($boards);
    }
}
