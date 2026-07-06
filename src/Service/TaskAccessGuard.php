<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TaskAccessGuard
{
    public function __construct(
        private TeamMemberRepositoryInterface $teamMemberRepository,
    ) {
    }

    public function canTrack(UserInterface $user, Task $task): bool
    {
        $userId   = UserIdResolver::getId($user);
        $assignee = $task->getAssignee();

        if ($assignee !== null && method_exists($assignee, 'getId') && (string) $assignee->getId() === $userId) {
            return true;
        }

        $team = $task->getBoard()->getTeam();
        if (!$team instanceof \Nowo\TaskBoardBundle\Entity\Team) {
            return $assignee === null;
        }

        foreach ($this->teamMemberRepository->findByUserId($userId) as $membership) {
            if ($membership->getTeam()->getId() === $team->getId()) {
                return true;
            }
        }

        return false;
    }
}
