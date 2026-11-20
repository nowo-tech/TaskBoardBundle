<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

use function array_map;
use function array_unique;
use function array_values;
use function method_exists;

/**
 * Since 2.28.0: TimeTrack's team directory (TimeTrackBundle >= 1.23, the framework-free twin of its team context
 * provider; REQ-ARCH-001 rule 3). The service `nowo_task_board.team_context_provider` (TimeTrack's
 * `team_context_provider` option) is this class. Same answers as {@see TaskBoardTeamContextProvider}, keyed by the
 * reference's user id; a reference without an id belongs to no team and manages nobody.
 */
final readonly class TaskBoardTeamDirectory implements TeamDirectoryInterface
{
    public function __construct(
        private TeamMemberRepositoryInterface $teamMemberRepository,
    ) {
    }

    public function teamIdsOf(TimeTrackUserRef $user): array
    {
        if ($user->id === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (TeamMember $member): string => $member->getTeam()->getId(),
            $this->teamMemberRepository->findByUserId($user->id),
        )));
    }

    public function manages(TimeTrackUserRef $manager, TimeTrackUserRef $member): bool
    {
        return $manager->id !== null && $member->id !== null && $this->teamMemberRepository->isManagerOf($manager->id, $member->id);
    }

    public function managedUserIdsOf(TimeTrackUserRef $manager): array
    {
        if ($manager->id === null) {
            return [];
        }
        $ids = [];
        foreach ($this->teamMemberRepository->findMembersManagedBy($manager->id) as $member) {
            $user = $member->getUser();
            if (method_exists($user, 'getId')) {
                $ids[] = (string) $user->getId();
            }
        }

        return array_values(array_unique($ids));
    }
}
