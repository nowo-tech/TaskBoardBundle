<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TaskBoardTeamContextProvider implements TeamContextProviderInterface
{
    public function __construct(
        private TeamMemberRepositoryInterface $teamMemberRepository,
    ) {
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        $userId = UserIdResolver::getId($user);

        return array_values(array_unique(array_map(
            static fn (\Nowo\TaskBoardBundle\Entity\TeamMember $member): string => $member->getTeam()->getId(),
            $this->teamMemberRepository->findByUserId($userId),
        )));
    }

    public function isManagerOf(UserInterface $manager, UserInterface $member): bool
    {
        return $this->teamMemberRepository->isManagerOf(
            UserIdResolver::getId($manager),
            UserIdResolver::getId($member),
        );
    }

    public function getManagedUserIds(UserInterface $manager): array
    {
        $ids = [];
        foreach ($this->teamMemberRepository->findMembersManagedBy(UserIdResolver::getId($manager)) as $member) {
            $user = $member->getUser();
            if (method_exists($user, 'getId')) {
                $ids[] = (string) $user->getId();
            }
        }

        return array_values(array_unique($ids));
    }
}
