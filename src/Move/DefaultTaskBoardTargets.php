<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Move;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;

use function array_filter;
use function array_values;

/**
 * Since 2.3.0 (spec 217): the active boards of the task's tenant (every active board without a tenant key) that the user
 * may edit.
 */
final readonly class DefaultTaskBoardTargets implements TaskBoardTargetsInterface
{
    public function __construct(
        private TaskBoardRepositoryInterface $boards,
        private TaskBoardResourceAccessChecker $access,
    ) {
    }

    public function targets(Task $task, object $user): array
    {
        $tenant     = $task->getBoard()->getTenantKey();
        $candidates = $tenant !== null ? $this->boards->findActiveByTenant($tenant) : array_values(array_filter($this->boards->findAllActive(), static fn (TaskBoard $board): bool => $board->getTenantKey() === null));

        return array_values(array_filter($candidates, fn (TaskBoard $board): bool => $this->access->canEditBoard($user, $board)));
    }
}
