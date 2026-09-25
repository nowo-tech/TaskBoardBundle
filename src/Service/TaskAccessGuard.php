<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TaskAccessGuard
{
    public function __construct(
        private TeamMemberRepositoryInterface $teamMemberRepository,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function canTrack(UserInterface $user, Task $task): bool
    {
        $this->refreshForDecision($task);

        $userId   = UserIdResolver::getId($user);
        $assignee = $task->getAssignee();

        if ($assignee !== null && method_exists($assignee, 'getId') && (string) $assignee->getId() === $userId) {
            return true;
        }

        $team = $task->getBoard()->getTeam();
        if (!$team instanceof Team) {
            return $assignee === null;
        }

        foreach ($this->teamMemberRepository->findByUserId($userId) as $membership) {
            if ($membership->getTeam()->getId() === $team->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The task may still be in the identity map from an earlier request of a long-running worker:
     * reload its assignees and board team from the database before deciding.
     */
    private function refreshForDecision(Task $task): void
    {
        $entityManager = $this->managerRegistry?->getManagerForClass(Task::class);
        if (!$entityManager instanceof EntityManagerInterface || !$entityManager->isOpen() || !$entityManager->contains($task)) {
            return;
        }

        $entityManager->refresh($task);

        $board = $task->getBoard();
        if ($entityManager->contains($board)) {
            $entityManager->refresh($board);
        }
    }
}
